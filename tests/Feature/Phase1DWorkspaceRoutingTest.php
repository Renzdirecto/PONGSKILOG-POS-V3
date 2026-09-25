<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function phase1DUser(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('name', $roleName)->sole();
    $user->roles()->attach($role);

    return $user;
}

function phase1DAssignBranch(User $user, Branch $branch, bool $isActive = true): void
{
    $user->branches()->attach($branch, ['is_active' => $isActive]);
}

test('staff with one active assignment skips branch selection', function () {
    $user = phase1DUser('cashier');
    $branch = Branch::factory()->create();
    phase1DAssignBranch($user, $branch);

    $response = $this->actingAs($user)->get(route('workspace'));

    $response
        ->assertRedirectToRoute('workspaces.cashier')
        ->assertSessionHas(ActiveBranchContext::SESSION_KEY, $branch->getKey());
});

test('staff with multiple active assignments is sent to branch selection', function () {
    $user = phase1DUser('cashier');
    $branches = Branch::factory()->count(2)->create();
    $user->branches()->attach($branches, ['is_active' => true]);

    $response = $this->actingAs($user)->get(route('workspace'));

    $response
        ->assertRedirectToRoute('branches.select')
        ->assertSessionMissing(ActiveBranchContext::SESSION_KEY);
});

test('staff with no active assignment receives a safe no branch state', function () {
    $user = phase1DUser('kitchen_staff');

    $response = $this->actingAs($user)->get(route('workspace'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('branches/unassigned')
        ->where('branchContext.current', null)
        ->where('branchContext.businessWide', false)
        ->has('branchContext.selectableBranches', 0));
});

test('forged branch selection is forbidden without changing active context', function () {
    $user = phase1DUser('cashier');
    $assignedBranch = Branch::factory()->create();
    $forgedBranch = Branch::factory()->create();
    phase1DAssignBranch($user, $assignedBranch);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $assignedBranch->getKey()])
        ->put(route('branch-context.update', $forgedBranch));

    $response->assertForbidden();
    $response->assertSessionHas(ActiveBranchContext::SESSION_KEY, $assignedBranch->getKey());
});

test('inactive assignment cannot be selected', function () {
    $user = phase1DUser('kitchen_staff');
    $branch = Branch::factory()->create();
    phase1DAssignBranch($user, $branch, false);

    $response = $this
        ->actingAs($user)
        ->put(route('branch-context.update', $branch));

    $response
        ->assertForbidden()
        ->assertSessionMissing(ActiveBranchContext::SESSION_KEY);
});

test('authorized branch selection stores the active branch', function () {
    $user = phase1DUser('cashier');
    $branch = Branch::factory()->create();
    phase1DAssignBranch($user, $branch);

    $response = $this
        ->actingAs($user)
        ->put(route('branch-context.update', $branch));

    $response
        ->assertRedirectToRoute('workspace')
        ->assertSessionHas(ActiveBranchContext::SESSION_KEY, $branch->getKey());
});

test('authorized branch switch replaces the active branch', function () {
    $user = phase1DUser('cashier');
    $firstBranch = Branch::factory()->create();
    $secondBranch = Branch::factory()->create();
    phase1DAssignBranch($user, $firstBranch);
    phase1DAssignBranch($user, $secondBranch);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $firstBranch->getKey()])
        ->put(route('branch-context.update', $secondBranch));

    $response
        ->assertRedirectToRoute('workspace')
        ->assertSessionHas(ActiveBranchContext::SESSION_KEY, $secondBranch->getKey());
});

test('normal staff cannot clear to all branches', function () {
    $user = phase1DUser('cashier');
    $branch = Branch::factory()->create();
    phase1DAssignBranch($user, $branch);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->delete(route('branch-context.destroy'));

    $response->assertForbidden();
    $response->assertSessionHas(ActiveBranchContext::SESSION_KEY, $branch->getKey());
});

test('business wide users can clear to all branches', function (string $roleName) {
    $user = phase1DUser($roleName);
    $branch = Branch::factory()->create();

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->delete(route('branch-context.destroy'));

    $response
        ->assertRedirectToRoute('workspace')
        ->assertSessionMissing(ActiveBranchContext::SESSION_KEY);
})->with(['owner', 'super_admin']);

test('business wide users can select any branch', function (string $roleName) {
    $user = phase1DUser($roleName);
    $branch = Branch::factory()->create();

    $response = $this
        ->actingAs($user)
        ->put(route('branch-context.update', $branch));

    $response
        ->assertRedirectToRoute('workspace')
        ->assertSessionHas(ActiveBranchContext::SESSION_KEY, $branch->getKey());
})->with(['owner', 'super_admin']);

test('roles route to their deterministic workspace', function (string $roleName, string $routeName) {
    $user = phase1DUser($roleName);

    if (! in_array($roleName, ['owner', 'super_admin'], true)) {
        $branch = Branch::factory()->create();
        phase1DAssignBranch($user, $branch);
    }

    $response = $this->actingAs($user)->get(route('workspace'));

    $response->assertRedirectToRoute($routeName);
})->with([
    'cashier' => ['cashier', 'workspaces.cashier'],
    'kitchen staff' => ['kitchen_staff', 'workspaces.kitchen'],
    'cashier kitchen' => ['cashier_kitchen', 'workspaces.cashier'],
    'owner' => ['owner', 'workspaces.owner'],
    'super admin' => ['super_admin', 'workspaces.super-admin'],
]);

test('branch selection exposes only active assignments', function () {
    $user = phase1DUser('cashier');
    $firstBranch = Branch::factory()->create(['name' => 'Alpha Branch', 'code' => 'ALPHA']);
    $secondBranch = Branch::factory()->create(['name' => 'Beta Branch', 'code' => 'BETA']);
    $inactiveBranch = Branch::factory()->create(['name' => 'Hidden Branch', 'code' => 'HIDDEN']);
    Branch::factory()->create(['name' => 'Unassigned Branch', 'code' => 'OTHER']);
    phase1DAssignBranch($user, $firstBranch);
    phase1DAssignBranch($user, $secondBranch);
    phase1DAssignBranch($user, $inactiveBranch, false);

    $response = $this->actingAs($user)->get(route('branches.select'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('branches/select')
        ->has('branchContext.selectableBranches', 2)
        ->where('branchContext.selectableBranches.0', [
            'id' => $firstBranch->getKey(),
            'name' => 'Alpha Branch',
            'code' => 'ALPHA',
        ])
        ->where('branchContext.selectableBranches.1', [
            'id' => $secondBranch->getKey(),
            'name' => 'Beta Branch',
            'code' => 'BETA',
        ]));
});

test('branch scoped workspaces require an active branch context', function () {
    $user = phase1DUser('cashier');

    $response = $this->actingAs($user)->get(route('workspaces.cashier'));

    $response->assertRedirectToRoute('workspace');
});

test('authenticated inertia props expose only minimal identity and branch context', function () {
    $user = phase1DUser('cashier');
    $branch = Branch::factory()->create([
        'name' => 'Main Branch',
        'code' => 'MAIN',
    ]);
    phase1DAssignBranch($user, $branch);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->get(route('workspaces.cashier'));

    $response->assertInertia(fn (Assert $page) => $page
        ->component('workspaces/show')
        ->where('auth.user', [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'position' => null,
        ])
        ->where('auth.roles', ['cashier'])
        ->where('auth.permissions', [
            'pos.access',
            'qr_orders.access',
            'store.open_close',
            'store_expenses.manage',
            'transactions.view',
        ])
        ->where('branchContext.current', [
            'id' => $branch->getKey(),
            'name' => 'Main Branch',
            'code' => 'MAIN',
        ])
        ->where('branchContext.businessWide', false)
        ->has('branchContext.selectableBranches', 1));
});
