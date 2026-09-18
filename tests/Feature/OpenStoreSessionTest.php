<?php

use App\Actions\StoreSessions\OpenStoreSession;
use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Connection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

function assignedStoreOpener(Branch $branch, string $roleName = 'cashier'): User
{
    test()->seed(RbacSeeder::class);
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

test('an assigned cashier opens an active branch with exact balances and opener information', function (string $role) {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch, $role);
    $this->travelTo(now()->startOfSecond());

    $session = app(OpenStoreSession::class)->execute($user, $branch, '5000.00', '1000.00')->refresh();

    expect($session->status)->toBe(StoreSessionStatus::Open);
    expect($session->branch_id)->toBe($branch->id);
    expect($session->opening_cash_amount)->toBe('5000.00');
    expect($session->opening_cashless_amount)->toBe('1000.00');
    expect($session->opened_by_user_id)->toBe($user->id);
    expect($session->opened_at->equalTo(now()))->toBeTrue();
    $this->assertDatabaseCount('store_sessions', 1);
})->with(['cashier', 'cashier_kitchen']);

test('a later cashier reuses the session without changing any original opening data', function () {
    $branch = Branch::factory()->create();
    $firstUser = assignedStoreOpener($branch);
    $secondUser = assignedStoreOpener($branch);
    $this->travelTo(now()->startOfSecond());
    $action = app(OpenStoreSession::class);
    $original = $action->execute($firstUser, $branch, '5000.00', '1000.00')->refresh();
    $attributes = $original->getAttributes();
    $this->travel(10)->minutes();

    $reused = $action->execute($secondUser, $branch, '1.00', '2.00');

    expect($reused->id)->toBe($original->id);
    expect($reused->refresh()->getAttributes())->toBe($attributes);
    expect($branch->storeSessions()->where('status', StoreSessionStatus::Open)->count())->toBe(1);
});

test('inactive users are rejected even when the supplied model still appears active', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    User::query()->whereKey($user->id)->update(['is_active' => false]);

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('a cashier without the open close permission cannot open a store', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', 'store.open_close')->sole());

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('non cashier roles cannot open a store even if explicitly granted the permission', function (string $role) {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch, $role);
    $user->roles()->sole()->permissions()->syncWithoutDetaching([
        Permission::query()->where('name', 'store.open_close')->sole()->id,
    ]);

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
})->with(['super_admin', 'owner', 'kitchen_staff']);

test('a cashier cannot open an unrelated branch or reuse its existing session', function (bool $alreadyOpen) {
    $assignedBranch = Branch::factory()->create();
    $user = assignedStoreOpener($assignedBranch);
    $otherBranch = Branch::factory()->create();
    if ($alreadyOpen) {
        StoreSession::factory()->for($otherBranch)->create();
    }

    expect(fn () => app(OpenStoreSession::class)->execute($user, $otherBranch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', $alreadyOpen ? 1 : 0);
    expect($assignedBranch->storeSessions()->count())->toBe(0);
})->with(['closed' => false, 'open' => true]);

test('an inactive branch assignment cannot open a store', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $user->load('branches');
    $user->branches()->updateExistingPivot($branch, ['is_active' => false]);

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('business wide scope cannot bypass the cashier branch assignment requirement', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $user->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    $user->branches()->detach();

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('a deleted user cannot open a store using a stale model', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    User::query()->whereKey($user->id)->delete();

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('revoked cashier roles override previously loaded role and permission relationships', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $user->load('roles.permissions', 'branches');
    $user->roles()->detach();

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '10', '20'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('an unsaved user cannot impersonate a persisted cashier', function () {
    $branch = Branch::factory()->create();
    $cashier = assignedStoreOpener($branch);
    $user = new User;
    $user->id = $cashier->id;

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('the locked branch status overrides stale or forged active model attributes', function (BranchStatus $status) {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    Branch::query()->whereKey($branch->id)->update(['status' => $status]);

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '0', '0'))
        ->toThrow(AuthorizationException::class, 'Only an active branch may be opened.');

    $this->assertDatabaseCount('store_sessions', 0);
})->with([BranchStatus::TemporarilyClosed, BranchStatus::Inactive]);

test('invalid opening money is rejected without creating a session', function (string $field, string $amount) {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $amounts = ['opening_cash_amount' => '0.00', 'opening_cashless_amount' => '0.00'];
    $amounts[$field] = $amount;

    try {
        app(OpenStoreSession::class)->execute($user, $branch, ...array_values($amounts));
        $this->fail('Invalid money was accepted.');
    } catch (ValidationException $exception) {
        expect(array_keys($exception->errors()))->toBe([$field]);
    }

    $this->assertDatabaseCount('store_sessions', 0);
})->with(['opening_cash_amount', 'opening_cashless_amount'])->with([
    'required' => '',
    'negative' => '-0.01',
    'too precise' => '1.001',
    'malformed' => 'abc',
    'scientific notation' => '1e2',
    'comma' => '1,000.00',
    'whitespace' => ' 1.00',
    'newline' => "1.00\n",
    'overflow' => '1000000000000.00',
]);

test('valid decimal boundaries round trip as exact money strings', function (string $amount, string $expected) {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);

    $session = app(OpenStoreSession::class)->execute($user, $branch, $amount, $amount)->refresh();

    expect($session->opening_cash_amount)->toBe($expected);
    expect($session->opening_cashless_amount)->toBe($expected);
})->with([
    'zero' => ['0', '0.00'],
    'one decimal' => ['12.3', '12.30'],
    'cent' => ['0.01', '0.01'],
    'large exact decimal' => ['123456789.12', '123456789.12'],
    'maximum' => ['999999999999.99', '999999999999.99'],
]);

test('each branch opens independently and closed history is retained', function () {
    $firstBranch = Branch::factory()->create();
    $secondBranch = Branch::factory()->create();
    $user = assignedStoreOpener($firstBranch);
    $user->branches()->attach($secondBranch, ['is_active' => true]);
    $history = StoreSession::factory()->closed()->for($firstBranch)->create();
    $action = app(OpenStoreSession::class);

    $first = $action->execute($user, $firstBranch, '10.00', '20.00');
    expect($secondBranch->storeSessions()->count())->toBe(0);
    $second = $action->execute($user, $secondBranch, '30.00', '40.00');

    expect($first->id)->not->toBe($second->id);
    expect($first->refresh()->opening_cash_amount)->toBe('10.00');
    expect($second->refresh()->opening_cash_amount)->toBe('30.00');
    expect($history->refresh()->status)->toBe(StoreSessionStatus::Closed);
    $this->assertDatabaseCount('store_sessions', 3);
});

test('the branch lock and session insert run inside the action transaction', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $baseLevel = DB::transactionLevel();
    $observed = [];
    $locks = [];
    Branch::addGlobalScope('observe_branch_lock', function (Builder $query) use (&$locks): void {
        $query->getQuery()->beforeQuery(function (QueryBuilder $query) use (&$locks): void {
            $locks[] = [$query->lock, DB::transactionLevel()];
        });
    });
    DB::connection()->beforeExecuting(function (string $sql, array $bindings, Connection $connection) use (&$observed): void {
        if (str_contains($sql, '"branches"') || str_starts_with($sql, 'insert into "store_sessions"')) {
            $observed[] = [$sql, $connection->transactionLevel()];
        }
    });

    app(OpenStoreSession::class)->execute($user, $branch, '0', '0');

    expect($observed)->not->toBeEmpty();
    foreach ($observed as [$sql, $level]) {
        expect($level)->toBeGreaterThan($baseLevel);
    }
    expect($observed[0][0])->toContain('"branches"', '"id" = ?');
    expect($locks[0])->toBe([true, $baseLevel + 1]);
    expect(DB::transactionLevel())->toBe($baseLevel);
});

test('the specific PostgreSQL open session conflict recovers the existing session after savepoint rollback', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $winner = StoreSession::factory()->for($branch)->for($user, 'openedBy')->make([
        'id' => (string) Str::uuid(),
        'opening_cash_amount' => '5000.00',
        'opening_cashless_amount' => '1000.00',
    ]);
    $inserted = false;
    DB::listen(function (QueryExecuted $query) use ($winner, &$inserted): void {
        if (! $inserted && str_starts_with($query->sql, 'select * from "store_sessions"')) {
            $inserted = true;
            DB::table('store_sessions')->insert($winner->getAttributes());
        }
    });
    $previous = new PDOException('Duplicate open session');
    $previous->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "store_sessions_one_open_per_branch_unique"'];
    $conflict = new UniqueConstraintViolationException('pgsql', 'insert', [], $previous);
    StoreSession::creating(function () use ($conflict): void {
        throw $conflict;
    });

    $session = app(OpenStoreSession::class)->execute($user, $branch, '1.00', '2.00');

    expect($session->id)->toBe($winner->id);
    expect($session->opening_cash_amount)->toBe('5000.00');
    expect($session->opening_cashless_amount)->toBe('1000.00');
    $this->assertDatabaseCount('store_sessions', 1);
});

test('unique errors propagate unless the named open session conflict has a matching session', function (string $constraint) {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $previous = new PDOException('Duplicate key');
    $previous->errorInfo = ['23505', 7, 'duplicate key value violates unique constraint "'.$constraint.'"'];
    $conflict = new UniqueConstraintViolationException('pgsql', 'insert', [], $previous);
    StoreSession::creating(function () use ($conflict): void {
        throw $conflict;
    });

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '10', '20'))
        ->toThrow($conflict);

    $this->assertDatabaseCount('store_sessions', 0);
})->with(['store_sessions_pkey', 'store_sessions_one_open_per_branch_unique']);

test('a failed session write is rolled back and unrelated database errors propagate', function () {
    $branch = Branch::factory()->create();
    $user = assignedStoreOpener($branch);
    $exception = new QueryException('sqlite', 'insert', [], new RuntimeException('Write failed'));
    StoreSession::created(function () use ($exception): void {
        throw $exception;
    });

    expect(fn () => app(OpenStoreSession::class)->execute($user, $branch, '10', '20'))
        ->toThrow($exception);

    $this->assertDatabaseCount('store_sessions', 0);
});
