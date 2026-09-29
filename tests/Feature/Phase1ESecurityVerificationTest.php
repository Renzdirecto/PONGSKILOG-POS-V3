<?php

use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function phase1EUser(string $roleName): User
{
    $user = User::factory()->create();
    $role = Role::query()->where('name', $roleName)->sole();
    $user->roles()->attach($role);

    return $user;
}

function phase1EAssignBranch(User $user): Branch
{
    $branch = Branch::factory()->create();
    $user->branches()->attach($branch, ['is_active' => true]);

    return $branch;
}

test('guests are redirected from every phase one workspace and branch selection route', function (string $routeName) {
    $this->get(route($routeName))->assertRedirect(route('login'));
})->with([
    'workspace router' => 'workspace',
    'branch selector' => 'branches.select',
    'cashier workspace' => 'workspaces.cashier',
    'kitchen workspace' => 'workspaces.kitchen',
    'owner workspace' => 'workspaces.owner',
    'super admin workspace' => 'workspaces.super-admin',
]);

test('branch selection requires authentication', function () {
    $branch = Branch::factory()->create();

    $this->put(route('branch-context.update', $branch))
        ->assertRedirect(route('login'));
});

test('clearing branch context requires authentication', function () {
    $this->delete(route('branch-context.destroy'))
        ->assertRedirect(route('login'));
});

test('direct workspace access is forbidden without the required permission', function (string $roleName, string $routeName) {
    $user = phase1EUser($roleName);
    $branch = phase1EAssignBranch($user);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->get(route($routeName));

    $response->assertForbidden();
})->with([
    'kitchen to cashier' => ['kitchen_staff', 'workspaces.cashier'],
    'kitchen to owner' => ['kitchen_staff', 'workspaces.owner'],
    'kitchen to super admin' => ['kitchen_staff', 'workspaces.super-admin'],
    'cashier to kitchen' => ['cashier', 'workspaces.kitchen'],
    'cashier to owner' => ['cashier', 'workspaces.owner'],
    'cashier to super admin' => ['cashier', 'workspaces.super-admin'],
    'owner to super admin' => ['owner', 'workspaces.super-admin'],
]);

test('cashier kitchen can access both intended branch workspaces', function (string $routeName, string $component, ?string $workspace) {
    $user = phase1EUser('cashier_kitchen');
    $branch = phase1EAssignBranch($user);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->get(route($routeName));

    $response->assertInertia(function (Assert $page) use ($component, $workspace): void {
        $page->component($component);

        if ($workspace !== null) {
            $page->where('workspace', $workspace);
        }
    });
})->with([
    'cashier workspace' => ['workspaces.cashier', 'workspaces/show', 'Cashier / POS'],
    'kitchen workspace' => ['workspaces.kitchen', 'workspaces/kitchen', null],
]);

test('stale branch context is cleared after an assignment is removed', function () {
    $user = phase1EUser('cashier');
    $branch = phase1EAssignBranch($user);
    $user->branches()->detach($branch);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->get(route('workspaces.cashier'));

    $response
        ->assertRedirectToRoute('workspace')
        ->assertSessionMissing(ActiveBranchContext::SESSION_KEY);
});

test('a forged nonexistent branch identifier returns not found without replacing the active branch', function () {
    $user = phase1EUser('cashier');
    $branch = phase1EAssignBranch($user);

    $response = $this
        ->actingAs($user)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->getKey()])
        ->put(route('branch-context.update', ['branch' => Str::uuid()->toString()]));

    $response
        ->assertNotFound()
        ->assertSessionHas(ActiveBranchContext::SESSION_KEY, $branch->getKey());
});

test('the default database seeder creates rbac metadata without development accounts', function () {
    $this->seed(DatabaseSeeder::class);

    $this->assertDatabaseCount('roles', 5);
    $this->assertDatabaseCount('permissions', 16);
    $this->assertDatabaseCount('users', 0);
});
