<?php

use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('an open store session persists with a UUID and nullable closing fields', function () {
    $this->travelTo(now()->startOfSecond());

    $session = StoreSession::factory()->create()->refresh();

    $this->assertModelExists($session);
    expect($session->id)->toBeUuid();
    expect($session->status)->toBe(StoreSessionStatus::Open);
    expect($session->opened_at)->toBeInstanceOf(CarbonInterface::class);
    expect($session->opened_at->equalTo(now()))->toBeTrue();
    expect($session->only([
        'closing_cash_amount', 'closing_cashless_amount',
        'expected_cash_amount', 'expected_cashless_amount',
        'cash_variance', 'cashless_variance', 'closing_note',
        'closed_by_user_id', 'closed_at',
    ]))->each->toBeNull();
    expect($session->closedBy)->toBeNull();
});

test('store sessions belong to their branch and opening and closing users', function () {
    $branch = Branch::factory()->create();
    $opener = User::factory()->create();
    $closer = User::factory()->create();
    $this->travelTo(now()->startOfSecond());

    $session = StoreSession::factory()->closed()
        ->for($branch)->for($opener, 'openedBy')->for($closer, 'closedBy')
        ->create()->refresh();

    expect($session->branch->is($branch))->toBeTrue();
    expect($session->openedBy->is($opener))->toBeTrue();
    expect($session->closedBy->is($closer))->toBeTrue();
    expect($branch->storeSessions()->sole()->is($session))->toBeTrue();
    expect($opener->openedStoreSessions()->sole()->is($session))->toBeTrue();
    expect($closer->closedStoreSessions()->sole()->is($session))->toBeTrue();
    expect($opener->closedStoreSessions)->toBeEmpty();
    expect($closer->openedStoreSessions)->toBeEmpty();
    expect($session->status)->toBe(StoreSessionStatus::Closed);
    expect($session->closed_at)->toBeInstanceOf(CarbonInterface::class);
    expect($session->closed_at->equalTo(now()))->toBeTrue();
});

test('the database rejects a second open session in the same branch', function () {
    $session = StoreSession::factory()->create();
    $duplicate = $session->getAttributes();
    $duplicate['id'] = (string) Str::uuid();

    expect(fn () => DB::table('store_sessions')->insert($duplicate))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('different branches can each have an open session', function () {
    $first = StoreSession::factory()->create();
    $second = StoreSession::factory()->create();

    expect($first->branch_id)->not->toBe($second->branch_id);
    $this->assertModelExists($first);
    $this->assertModelExists($second);
});

test('multiple closed sessions can coexist with an open session in one branch', function () {
    $branch = Branch::factory()->create();

    StoreSession::factory()->closed()->for($branch)->count(2)->create();
    StoreSession::factory()->for($branch)->create();

    expect($branch->storeSessions()->where('status', StoreSessionStatus::Closed)->count())->toBe(2);
    expect($branch->storeSessions()->where('status', StoreSessionStatus::Open)->count())->toBe(1);
});

test('the database rejects changing a closed row to a duplicate open session', function () {
    $open = StoreSession::factory()->create();
    $closed = StoreSession::factory()->closed()->for($open->branch)->create();

    expect(fn () => DB::table('store_sessions')->where('id', $closed->id)->update(['status' => 'open']))
        ->toThrow(UniqueConstraintViolationException::class);
});

test('the database rejects negative opening closing and expected balances', function (string $column) {
    $attributes = StoreSession::factory()->make(['id' => (string) Str::uuid()])->getAttributes();
    $attributes[$column] = '-0.01';

    expect(fn () => DB::table('store_sessions')->insert($attributes))
        ->toThrow(QueryException::class, 'CHECK');
})->with([
    'opening cash' => 'opening_cash_amount',
    'opening cashless' => 'opening_cashless_amount',
    'closing cash' => 'closing_cash_amount',
    'closing cashless' => 'closing_cashless_amount',
    'expected cash' => 'expected_cash_amount',
    'expected cashless' => 'expected_cashless_amount',
]);

test('all money values round trip as decimal strings including signed variances', function () {
    $amounts = [
        'opening_cash_amount' => '123456789.12',
        'opening_cashless_amount' => '0.00',
        'closing_cash_amount' => '0.00',
        'closing_cashless_amount' => '2500.10',
        'expected_cash_amount' => '0.00',
        'expected_cashless_amount' => '2500.20',
        'cash_variance' => '0.01',
        'cashless_variance' => '-0.10',
    ];

    $session = StoreSession::factory()->closed()->create($amounts)->refresh();

    expect($session->only(array_keys($amounts)))->toBe($amounts);
});

test('the database requires status and opening amounts', function (string $column) {
    $attributes = StoreSession::factory()->make(['id' => (string) Str::uuid()])->getAttributes();
    unset($attributes[$column]);

    expect(fn () => DB::table('store_sessions')->insert($attributes))
        ->toThrow(QueryException::class, 'NOT NULL');
})->with(['status', 'opening_cash_amount', 'opening_cashless_amount']);

test('the database rejects unsupported store session statuses', function () {
    $session = StoreSession::factory()->create();

    expect(fn () => DB::table('store_sessions')->where('id', $session->id)->update(['status' => 'pending']))
        ->toThrow(QueryException::class, 'CHECK');
});

test('the database rejects missing branch and user references', function (string $column, string|int $missingId) {
    $session = StoreSession::factory()->create();

    expect(fn () => DB::table('store_sessions')->where('id', $session->id)->update([$column => $missingId]))
        ->toThrow(QueryException::class, 'FOREIGN KEY');
})->with([
    'branch' => ['branch_id', '00000000-0000-4000-8000-000000000001'],
    'opener' => ['opened_by_user_id', 999999],
    'closer' => ['closed_by_user_id', 999999],
]);

test('deleting a referenced branch or user cannot erase session history', function (string $relation) {
    $session = StoreSession::factory()->closed()->create();

    expect(fn () => $session->{$relation}->delete())
        ->toThrow(QueryException::class, 'FOREIGN KEY');
})->with(['branch', 'openedBy', 'closedBy']);
