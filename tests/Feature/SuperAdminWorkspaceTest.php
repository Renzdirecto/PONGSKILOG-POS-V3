<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function superAdminWorkspaceUser(string $roleName = 'super_admin', ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $roleName)->sole());

    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

test('super admin opens the control center landing page', function () {
    $this->actingAs(superAdminWorkspaceUser())
        ->get(route('workspaces.super-admin'))
        ->assertInertia(fn (Assert $page) => $page->component('super-admin/dashboard'));
});

test('planned super admin destinations render protected placeholders', function (string $routeName, string $destination) {
    $this->actingAs(superAdminWorkspaceUser())
        ->get(route($routeName))
        ->assertInertia(fn (Assert $page) => $page
            ->component('super-admin/placeholder')
            ->where('destination', $destination));
})->with([
    'notifications' => ['super-admin.notifications', 'notifications'],
    'access control' => ['super-admin.access-control', 'access-control'],
]);

test('guests are sent to login from super admin control center routes', function (string $routeName) {
    $this->get(route($routeName))->assertRedirectToRoute('login');
})->with(['workspaces.super-admin', 'super-admin.staff.index', 'super-admin.notifications', 'super-admin.access-control']);

test('other roles cannot open the super admin control center', function (string $roleName) {
    $branch = Branch::factory()->create();
    $user = superAdminWorkspaceUser($roleName, in_array($roleName, ['owner'], true) ? null : $branch);

    $this->actingAs($user)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id]);

    foreach (['workspaces.super-admin', 'super-admin.staff.index', 'super-admin.notifications', 'super-admin.access-control'] as $routeName) {
        $this->get(route($routeName))->assertForbidden();
    }
})->with(['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen']);

test('super admin opens every branch operational workspace for the selected branch without an assignment', function (string $routeName, string $component) {
    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $superAdmin = superAdminWorkspaceUser();

    $this->actingAs($superAdmin)
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route($routeName))
        ->assertInertia(fn (Assert $page) => $page->component($component));

    expect($superAdmin->branches()->count())->toBe(0);
})->with([
    'pos' => ['workspaces.cashier', 'workspaces/show'],
    'cashier dashboard' => ['workspaces.cashier-dashboard', 'workspaces/cashier-dashboard'],
    'transaction history' => ['workspaces.transaction-history', 'workspaces/transaction-history'],
    'kitchen' => ['workspaces.kitchen', 'workspaces/kitchen'],
    'customer display' => ['workspaces.customer-display', 'workspaces/customer-display'],
]);

test('super admin POS offers the real store controls for the selected branch', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(superAdminWorkspaceUser())
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('workspace', 'Cashier / POS')
            ->where('store.canOpen', true)
            ->where('branchContext.current.id', $branch->id));
});

test('super admin must choose a branch before branch operational workspaces open', function (string $routeName) {
    $this->actingAs(superAdminWorkspaceUser())
        ->get(route($routeName))
        ->assertRedirectToRoute('workspace');
})->with(['workspaces.cashier', 'workspaces.cashier-dashboard', 'workspaces.transaction-history', 'workspaces.kitchen', 'workspaces.customer-display']);

test('super admin opens the existing owner and control destinations', function (string $routeName, string $component) {
    $this->actingAs(superAdminWorkspaceUser())
        ->get(route($routeName))
        ->assertInertia(fn (Assert $page) => $page->component($component));
})->with([
    'owner dashboard' => ['workspaces.owner', 'workspaces/show'],
    'products' => ['products.index', 'catalog/products'],
    'inventory' => ['inventory.index', 'inventory/index'],
    'audit trail' => ['workspaces.audit-trail', 'super-admin/audit-trail'],
    'void orders' => ['workspaces.void-orders', 'super-admin/void-orders'],
    'settings' => ['branches.index', 'branches/index'],
]);

test('super admin operational access stays isolated to the selected branch', function () {
    $selectedBranch = Branch::factory()->create();
    StoreSession::factory()->for($selectedBranch)->create();
    $otherBranch = Branch::factory()->create();
    $foreignOrder = Order::factory()->for($otherBranch)->create(['committed_at' => now(), 'commercial_status' => 'active']);

    $this->actingAs(superAdminWorkspaceUser())
        ->withSession([ActiveBranchContext::SESSION_KEY => $selectedBranch->id])
        ->getJson(route('pos.transactions.show', $foreignOrder))
        ->assertNotFound();
});

test('super admin cannot operate a branch that is not active', function () {
    $branch = Branch::factory()->create(['status' => BranchStatus::Inactive]);

    $this->actingAs(superAdminWorkspaceUser())
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.cashier-dashboard'))
        ->assertForbidden();
});

test('owner business wide scope does not gain cashier operations', function () {
    $branch = Branch::factory()->create();

    $this->actingAs(superAdminWorkspaceUser('owner'))
        ->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.cashier'))
        ->assertForbidden();
});
