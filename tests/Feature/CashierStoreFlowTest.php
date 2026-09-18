<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function cashierFlowUser(Branch $branch, string $roleName = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

test('cashier sees closed store when only historical or other branch sessions exist', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    StoreSession::factory()->closed()->for($branch)->create();
    StoreSession::factory()->create();

    $this->actingAs($user)->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/show')
            ->where('branchContext.current.id', $branch->id)
            ->where('storeContext', ['status' => 'closed', 'isOpen' => false, 'branchId' => $branch->id])
            ->where('store', ['branchStatus' => 'active', 'canOpen' => true]));
});

test('existing open store is detected without exposing opening balances', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    StoreSession::factory()->for($branch)->create();

    $this->actingAs($user)->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext', ['status' => 'open', 'isOpen' => true, 'branchId' => $branch->id])
            ->where('store', ['branchStatus' => 'active', 'canOpen' => true])
            ->missing('storeSession')
            ->missing('opening_cash_amount')
            ->missing('opening_cashless_amount'));
});

test('opening a store requires authentication', function () {
    $this->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertRedirectToRoute('login');

    $this->assertDatabaseCount('store_sessions', 0);
});

test('non cashier roles cannot visit or open a cashier store even with permissions', function (string $roleName) {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch, $roleName);
    $user->roles()->sole()->permissions()->syncWithoutDetaching(
        Permission::query()->whereIn('name', ['pos.access', 'store.open_close'])->pluck('id'),
    );

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);
    $this->get(route('workspaces.cashier'))->assertForbidden();
    $this->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertForbidden();

    $this->assertDatabaseCount('store_sessions', 0);
})->with(['kitchen_staff', 'owner', 'super_admin']);

test('cashier opening requires both operation and workspace permissions', function (string $permission) {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', $permission)->sole());

    $this->actingAs($user)->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertForbidden();

    $this->assertDatabaseCount('store_sessions', 0);
})->with(['store.open_close', 'pos.access']);

test('cashier without opening permission receives read only store eligibility', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $user->roles()->sole()->permissions()->detach(Permission::query()->where('name', 'store.open_close')->sole());

    $this->actingAs($user)->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('store.canOpen', false));
});

test('inactive account cannot open a store', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $user->forceFill(['is_active' => false])->save();

    $this->actingAs($user)->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertRedirectToRoute('login');

    $this->assertGuest();
    $this->assertDatabaseCount('store_sessions', 0);
});

test('assigned cashier opens store and returns to an open workspace', function (string $roleName) {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch, $roleName);

    $this->actingAs($user)->post(route('store-sessions.open'), [
        'opening_cash_amount' => '1234.56',
        'opening_cashless_amount' => '0',
    ])->assertRedirectToRoute('workspaces.cashier')->assertSessionHasNoErrors();

    $session = StoreSession::query()->sole();
    expect($session->branch_id)->toBe($branch->id);
    expect($session->opened_by_user_id)->toBe($user->id);
    expect($session->opening_cash_amount)->toBe('1234.56');
    expect($session->opening_cashless_amount)->toBe('0.00');

    $this->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('storeContext', ['status' => 'open', 'isOpen' => true, 'branchId' => $branch->id])
        ->where('store', ['branchStatus' => 'active', 'canOpen' => true]));
})->with(['cashier', 'cashier_kitchen']);

test('both opening amounts are required', function () {
    $user = cashierFlowUser(Branch::factory()->create());

    $this->actingAs($user)->from(route('workspaces.cashier'))->post(route('store-sessions.open'), [])
        ->assertRedirectToRoute('workspaces.cashier')
        ->assertSessionHasErrors(['opening_cash_amount', 'opening_cashless_amount']);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('invalid opening amounts return inline errors without a store session', function (string $field, mixed $invalid) {
    $user = cashierFlowUser(Branch::factory()->create());

    $this->actingAs($user)->from(route('workspaces.cashier'))->post(route('store-sessions.open'), [
        'opening_cash_amount' => '0',
        'opening_cashless_amount' => '0',
        $field => $invalid,
    ])->assertRedirectToRoute('workspaces.cashier')->assertSessionHasErrors($field);

    $this->assertDatabaseCount('store_sessions', 0);
})->with(['opening_cash_amount', 'opening_cashless_amount'])->with([
    'negative' => '-1',
    'excess precision' => '1.001',
    'non numeric' => 'invalid',
    'structured payload' => [['amount' => '1']],
]);

test('active branch and actor are derived server side despite forged input', function () {
    $branch = Branch::factory()->create();
    $otherBranch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $user->branches()->attach($otherBranch, ['is_active' => true]);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->post(route('store-sessions.open'), [
            'opening_cash_amount' => '10',
            'opening_cashless_amount' => '20',
            'branch_id' => $otherBranch->id,
            'opened_by_user_id' => 999,
            'status' => 'closed',
        ])->assertRedirectToRoute('workspaces.cashier');

    $this->assertDatabaseHas('store_sessions', ['branch_id' => $branch->id, 'opened_by_user_id' => $user->id, 'status' => 'open']);
    $this->assertDatabaseMissing('store_sessions', ['branch_id' => $otherBranch->id]);

    $this->put(route('branch-context.update', $otherBranch))->assertRedirectToRoute('workspace');
    $this->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('branchContext.current.id', $otherBranch->id)->where('storeContext.status', 'closed'));
});

test('missing branch selection safely returns to the workspace router', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $user->branches()->attach(Branch::factory()->create(), ['is_active' => true]);

    $this->actingAs($user)->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertRedirectToRoute('workspace');

    $this->get(route('workspace'))->assertRedirectToRoute('branches.select');
    $this->assertDatabaseCount('store_sessions', 0);
});

test('revoked branch assignment prevents opening through stale context', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $user->branches()->updateExistingPivot($branch, ['is_active' => false]);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertRedirectToRoute('workspace')->assertSessionMissing(ActiveBranchContext::SESSION_KEY);

    $this->assertDatabaseCount('store_sessions', 0);
});

test('unavailable branches expose a safe state and reject opening', function (BranchStatus $status) {
    $branch = Branch::factory()->create(['status' => $status]);
    $user = cashierFlowUser($branch);

    $this->actingAs($user)->get(route('workspaces.cashier'))->assertInertia(fn (Assert $page) => $page
        ->where('store.branchStatus', $status->value)->where('store.canOpen', false));
    $this->post(route('store-sessions.open'), ['opening_cash_amount' => '0', 'opening_cashless_amount' => '0'])
        ->assertForbidden();

    $this->assertDatabaseCount('store_sessions', 0);
})->with([BranchStatus::TemporarilyClosed, BranchStatus::Inactive]);

test('repeated opening request succeeds without overwriting original session', function () {
    $branch = Branch::factory()->create();
    $user = cashierFlowUser($branch);
    $this->actingAs($user)->post(route('store-sessions.open'), ['opening_cash_amount' => '50.25', 'opening_cashless_amount' => '10'])
        ->assertRedirectToRoute('workspaces.cashier');
    $original = StoreSession::query()->sole()->getAttributes();

    $this->post(route('store-sessions.open'), ['opening_cash_amount' => '999', 'opening_cashless_amount' => '999'])
        ->assertRedirectToRoute('workspaces.cashier')->assertSessionHasNoErrors();

    expect(StoreSession::query()->sole()->getAttributes())->toBe($original);
});
