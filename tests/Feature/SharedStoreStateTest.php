<?php

use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\StoreState;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function sharedStoreUser(string $roleName = 'cashier'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());

    return $user;
}

test('store state reads current persisted sessions for the requested branch', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->closed()->for($branch)->create();
    StoreSession::factory()->create();
    $state = app(StoreState::class);

    expect($state->status($branch))->toBe(StoreSessionStatus::Closed);

    StoreSession::factory()->for($branch)->create();

    expect($state->status($branch))->toBe(StoreSessionStatus::Open);
});

test('another cashier sees the existing open store without changing its session', function () {
    $branch = Branch::factory()->create();
    $firstCashier = sharedStoreUser();
    $secondCashier = sharedStoreUser();
    $firstCashier->branches()->attach($branch, ['is_active' => true]);
    $secondCashier->branches()->attach($branch, ['is_active' => true]);
    $this->actingAs($firstCashier)->post(route('store-sessions.open'), [
        'opening_cash_amount' => '125.50',
        'opening_cashless_amount' => '20',
    ])->assertRedirectToRoute('workspaces.cashier');
    $original = StoreSession::query()->sole()->getAttributes();

    $this->actingAs($secondCashier)->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext', ['status' => 'open', 'isOpen' => true, 'branchId' => $branch->id])
            ->missing('store.status')
            ->missing('storeSession'));

    expect(StoreSession::query()->sole()->getAttributes())->toBe($original);
});

test('switching branches replaces store state on every redirected workspace visit', function () {
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $quezon = Branch::factory()->create(['code' => 'QAVE']);
    $user = sharedStoreUser();
    $user->branches()->attach([$main->id, $quezon->id], ['is_active' => true]);
    StoreSession::factory()->for($main)->create();
    $this->actingAs($user);

    foreach ([[$main, 'open', true], [$quezon, 'closed', false], [$main, 'open', true]] as [$branch, $status, $isOpen]) {
        $this->followingRedirects()->put(route('branch-context.update', $branch))
            ->assertInertia(fn (Assert $page) => $page
                ->component('workspaces/show')
                ->where('branchContext.current.id', $branch->id)
                ->where('storeContext', ['status' => $status, 'isOpen' => $isOpen, 'branchId' => $branch->id]));
    }
});

test('business wide scope has no store state until a specific branch is selected', function (string $role, string $route) {
    $main = Branch::factory()->create();
    $closedBranch = Branch::factory()->create();
    StoreSession::factory()->for($main)->create();
    $this->actingAs(sharedStoreUser($role));

    $this->get(route($route))->assertInertia(fn (Assert $page) => $page
        ->where('storeContext', ['status' => null, 'isOpen' => false, 'branchId' => null]));

    foreach ([[$main, 'open', true], [$closedBranch, 'closed', false]] as [$branch, $status, $isOpen]) {
        $this->followingRedirects()->put(route('branch-context.update', $branch))
            ->assertInertia(fn (Assert $page) => $page
                ->where('storeContext', ['status' => $status, 'isOpen' => $isOpen, 'branchId' => $branch->id]));
    }

    $this->followingRedirects()->delete(route('branch-context.destroy'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext', ['status' => null, 'isOpen' => false, 'branchId' => null]));
})->with([
    ['owner', 'workspaces.owner'],
    ['super_admin', 'workspaces.super-admin'],
]);

test('kitchen receives only the safe store projection even when reconciliation fields exist', function () {
    $branch = Branch::factory()->create();
    $user = sharedStoreUser('kitchen_staff');
    $user->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create([
        'opening_cash_amount' => '123.45',
        'opening_cashless_amount' => '67.89',
        'closing_cash_amount' => '100',
        'closing_cashless_amount' => '200',
        'expected_cash_amount' => '90',
        'expected_cashless_amount' => '190',
        'cash_variance' => '10',
        'cashless_variance' => '10',
        'closing_note' => 'Private reconciliation note',
    ]);

    $this->actingAs($user)->get(route('workspaces.kitchen'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext', ['status' => 'open', 'isOpen' => true, 'branchId' => $branch->id])
            ->missing('storeSession')
            ->missing('store')
            ->missing('opening_cash_amount')
            ->missing('opening_cashless_amount')
            ->missing('closing_cash_amount')
            ->missing('closing_cashless_amount')
            ->missing('expected_cash_amount')
            ->missing('expected_cashless_amount')
            ->missing('cash_variance')
            ->missing('cashless_variance')
            ->missing('closing_note')
            ->missing('opened_by_user_id'));
});

test('forged or inactive branch context cannot expose another branch store state', function (bool $inactiveAssignment) {
    $branch = Branch::factory()->create();
    $user = sharedStoreUser();
    StoreSession::factory()->for($branch)->create();
    if ($inactiveAssignment) {
        $user->branches()->attach($branch, ['is_active' => false]);
    }
    $this->actingAs($user);

    $this->put(route('branch-context.update', $branch))->assertForbidden();
    $this->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('home', ['branch_id' => $branch->id]))
        ->assertSessionMissing(ActiveBranchContext::SESSION_KEY)
        ->assertInertia(fn (Assert $page) => $page
            ->where('branchContext.current', null)
            ->where('storeContext', ['status' => null, 'isOpen' => false, 'branchId' => null]));
})->with(['unassigned' => false, 'inactive assignment' => true]);

test('guests cannot resolve store state from a stale session or query parameter', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();

    $this->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('home', ['branch_id' => $branch->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('storeContext', ['status' => null, 'isOpen' => false, 'branchId' => null]));
});
