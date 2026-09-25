<?php

use App\Enums\PermissionOverrideEffect;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\ActiveBranchContext;
use App\Support\PermissionCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create(['name' => 'Main', 'code' => 'MAIN']);
    $this->superAdmin = accessControlUser('super_admin');
});

function accessControlUser(string $role, ?Branch $branch = null, bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @return list<string> */
function roleBaseline(string $role): array
{
    return PermissionCatalog::ordered(Role::query()->where('name', $role)->sole()->permissions()->pluck('permissions.name')->all());
}

function overrideRow(User $user, string $permission): ?UserPermissionOverride
{
    return UserPermissionOverride::query()
        ->where('user_id', $user->id)
        ->where('permission_id', Permission::query()->where('name', $permission)->value('id'))
        ->first();
}

test('the seeded role baselines keep the existing default access', function () {
    expect(roleBaseline('owner'))->toBe(PermissionCatalog::ROLE_DEFAULTS['owner'])
        ->and(roleBaseline('cashier'))->toBe(PermissionCatalog::ROLE_DEFAULTS['cashier'])
        ->and(roleBaseline('kitchen_staff'))->toBe(PermissionCatalog::ROLE_DEFAULTS['kitchen_staff'])
        ->and(roleBaseline('cashier_kitchen'))->toBe(PermissionCatalog::union([PermissionCatalog::ROLE_DEFAULTS['cashier'], PermissionCatalog::ROLE_DEFAULTS['kitchen_staff']]))
        ->and(roleBaseline('super_admin'))->toBe(PermissionCatalog::names());
});

test('super admin sees the access control matrix with locked, derived and editable roles', function () {
    $this->actingAs($this->superAdmin)
        ->get(route('super-admin.access-control'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('super-admin/access-control')
            ->has('permissions', count(PermissionCatalog::names()))
            ->where('roles.4.name', 'super_admin')
            ->where('roles.4.kind', 'locked')
            ->where('roles.3.kind', 'derived')
            ->where('roles.1.name', 'cashier')
            ->where('roles.1.kind', 'editable')
            ->where('roles.1.locks', fn ($locks): bool => $locks['reports.view'] === null
                && $locks['audit.view'] === 'Super Admin only.'
                && $locks['products.manage'] !== null));
});

test('access control is refused to every other role', function (string $role) {
    $user = accessControlUser($role, in_array($role, ['owner'], true) ? null : $this->branch);

    $this->actingAs($user)->get(route('super-admin.access-control'))->assertForbidden();
    $this->actingAs($user)->put(route('super-admin.access-control.roles.update', 'cashier'), ['permissions' => []])->assertForbidden();
    $this->actingAs($user)->put(route('super-admin.access-control.users.update', $user), ['overrides' => []])->assertForbidden();
})->with(['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen']);

test('guests and inactive super admins cannot reach access control', function () {
    $this->get(route('super-admin.access-control'))->assertRedirectToRoute('login');

    $inactive = accessControlUser('super_admin', active: false);
    $this->actingAs($inactive)->get(route('super-admin.access-control'))->assertRedirectToRoute('login');
});

test('editing the cashier baseline changes every cashier and re-derives cashier plus kitchen', function () {
    $cashier = accessControlUser('cashier', $this->branch);
    $cashierKitchen = accessControlUser('cashier_kitchen', $this->branch);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'cashier'), [
            'permissions' => ['pos.access', 'transactions.view', 'store.open_close', 'reports.view'],
        ])
        ->assertRedirect();

    expect(roleBaseline('cashier'))->toBe(['pos.access', 'qr_orders.access', 'transactions.view', 'store.open_close', 'reports.view'])
        ->and(roleBaseline('cashier_kitchen'))->toBe(['pos.access', 'qr_orders.access', 'transactions.view', 'store.open_close', 'kitchen.access', 'customer_display.launch', 'reports.view'])
        ->and($cashier->hasPermission('store_expenses.manage'))->toBeFalse()
        ->and($cashier->hasPermission('reports.view'))->toBeTrue()
        ->and($cashierKitchen->hasPermission('store_expenses.manage'))->toBeFalse();

    $audit = AuditLog::query()->where('action', 'access.role_permissions_updated')->sole();
    expect($audit->user_id)->toBe($this->superAdmin->id)
        ->and($audit->metadata['added'])->toBe(['reports.view'])
        ->and($audit->metadata['removed'])->toBe(['store_expenses.manage'])
        ->and($audit->metadata['derived_cashier_kitchen']['after'])->toContain('reports.view');
});

test('editing the kitchen staff baseline also re-derives cashier plus kitchen', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'kitchen_staff'), ['permissions' => ['kitchen.access']])
        ->assertRedirect();

    expect(roleBaseline('kitchen_staff'))->toBe(['kitchen.access'])
        ->and(roleBaseline('cashier_kitchen'))->not->toContain('customer_display.launch');
});

test('removing pos from the cashier baseline also removes qr orders, which follows pos', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'cashier'), ['permissions' => ['transactions.view']])
        ->assertRedirect();

    expect(roleBaseline('cashier'))->toBe(['transactions.view']);
});

test('editing the owner baseline changes owners only', function () {
    $owner = accessControlUser('owner');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'owner'), [
            'permissions' => ['transactions.view', 'reports.view', 'inventory.manage', 'staff.manage', 'settings.manage'],
        ])
        ->assertRedirect();

    expect($owner->hasPermission('products.manage'))->toBeFalse()
        ->and(roleBaseline('cashier'))->toBe(PermissionCatalog::ROLE_DEFAULTS['cashier']);
});

test('super admin and cashier plus kitchen baselines cannot be edited directly', function (string $role) {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', $role), ['permissions' => []])
        ->assertSessionHasErrors('role');

    expect(roleBaseline('super_admin'))->toBe(PermissionCatalog::names());
})->with(['super_admin', 'cashier_kitchen']);

test('control and business-wide permissions cannot be granted to a branch role baseline', function (string $role, string $permission) {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', $role), [
            'permissions' => [...PermissionCatalog::ROLE_DEFAULTS[$role], $permission],
        ])
        ->assertSessionHasErrors('permissions');

    expect(roleBaseline($role))->not->toContain($permission);
})->with([
    'cashier audit' => ['cashier', 'audit.view'],
    'cashier void orders' => ['cashier', 'void_orders.manage'],
    'cashier access control' => ['cashier', 'access_control.manage'],
    'cashier staff' => ['cashier', 'staff.manage'],
    'cashier settings' => ['cashier', 'settings.manage'],
    'cashier products' => ['cashier', 'products.manage'],
    'kitchen pos' => ['kitchen_staff', 'pos.access'],
    'owner pos' => ['owner', 'pos.access'],
    'owner access control' => ['owner', 'access_control.manage'],
]);

test('unknown permission names are rejected instead of ignored', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'cashier'), ['permissions' => ['pos.access', 'root.everything']])
        ->assertSessionHasErrors('permissions.1');

    $juan = accessControlUser('cashier', $this->branch);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), ['overrides' => ['root.everything' => 'allow']])
        ->assertSessionHasErrors('overrides');
});

test('an unchanged baseline submission records nothing', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'kitchen_staff'), ['permissions' => ['kitchen.access', 'customer_display.launch']])
        ->assertRedirect();

    expect(AuditLog::query()->where('action', 'access.role_permissions_updated')->exists())->toBeFalse();
});

test('custom access allows and denies single permissions for one cashier only', function () {
    $juan = accessControlUser('cashier', $this->branch);
    $pedro = accessControlUser('cashier', $this->branch);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), [
            'overrides' => ['reports.view' => 'allow', 'store_expenses.manage' => 'deny', 'pos.access' => 'inherit'],
        ])
        ->assertRedirect(route('super-admin.access-control', ['tab' => 'staff', 'user' => $juan->id]));

    expect($juan->hasPermission('reports.view'))->toBeTrue()
        ->and($juan->hasPermission('store_expenses.manage'))->toBeFalse()
        ->and($juan->hasPermission('pos.access'))->toBeTrue()
        ->and($pedro->hasPermission('reports.view'))->toBeFalse()
        ->and($pedro->hasPermission('store_expenses.manage'))->toBeTrue()
        ->and($juan->hasRole('cashier'))->toBeTrue()
        ->and(overrideRow($juan, 'reports.view')?->effect)->toBe(PermissionOverrideEffect::Allow)
        ->and(overrideRow($juan, 'store_expenses.manage')?->effect)->toBe(PermissionOverrideEffect::Deny)
        ->and(overrideRow($juan, 'pos.access'))->toBeNull();

    $audit = AuditLog::query()->where('action', 'access.user_override_updated')->sole();
    expect($audit->auditable_id)->toBe((string) $juan->id)
        ->and($audit->before['custom_access'])->toBe([])
        ->and($audit->after['custom_access'])->toBe(['reports.view' => 'allow', 'store_expenses.manage' => 'deny']);
});

test('the shared permission list and navigation follow the effective permissions', function () {
    $juan = accessControlUser('cashier', $this->branch);
    UserPermissionOverride::query()->create([
        'user_id' => $juan->id,
        'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    $this->actingAs($juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.permissions', [
            'pos.access',
            'qr_orders.access',
            'reports.view',
            'store.open_close',
            'store_expenses.manage',
            'transactions.view',
        ]));
});

test('overrides equal to the role default are not stored', function () {
    $juan = accessControlUser('cashier', $this->branch);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), [
            'overrides' => ['pos.access' => 'allow', 'kitchen.access' => 'deny'],
        ])
        ->assertRedirect();

    expect(UserPermissionOverride::query()->where('user_id', $juan->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'access.user_override_updated')->exists())->toBeFalse();
});

test('reset removes every custom access and returns the account to its role', function () {
    $juan = accessControlUser('cashier', $this->branch);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), ['overrides' => ['reports.view' => 'allow', 'pos.access' => 'deny']]);

    $this->actingAs($this->superAdmin)
        ->delete(route('super-admin.access-control.users.reset', $juan))
        ->assertRedirect();

    expect(UserPermissionOverride::query()->where('user_id', $juan->id)->exists())->toBeFalse()
        ->and($juan->hasPermission('reports.view'))->toBeFalse()
        ->and($juan->hasPermission('pos.access'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'access.user_overrides_reset')->sole()->before['custom_access'])
        ->toBe(['pos.access' => 'deny', 'reports.view' => 'allow']);
});

test('custom access cannot grant control or business-wide permissions to branch staff', function (string $role, string $permission) {
    $user = accessControlUser($role, $this->branch);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $user), ['overrides' => [$permission => 'allow']])
        ->assertSessionHasErrors('overrides.'.$permission);

    expect($user->hasPermission($permission))->toBeFalse();
})->with([
    ['cashier', 'audit.view'],
    ['cashier', 'void_orders.manage'],
    ['cashier', 'access_control.manage'],
    ['cashier', 'staff.manage'],
    ['cashier', 'settings.manage'],
    ['cashier', 'inventory.manage'],
    ['cashier_kitchen', 'products.manage'],
    ['kitchen_staff', 'pos.access'],
    ['kitchen_staff', 'store_expenses.manage'],
]);

test('a stray allow row never grants a super admin only permission', function () {
    $juan = accessControlUser('cashier', $this->branch);
    UserPermissionOverride::query()->create([
        'user_id' => $juan->id,
        'permission_id' => Permission::query()->where('name', 'access_control.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    expect($juan->hasPermission('access_control.manage'))->toBeFalse();
    $this->actingAs($juan)->get(route('super-admin.access-control'))->assertForbidden();
});

test('super admin accounts are locked full access and never take overrides', function () {
    $other = accessControlUser('super_admin');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $other), ['overrides' => ['audit.view' => 'deny']])
        ->assertSessionHasErrors('user');

    UserPermissionOverride::query()->create([
        'user_id' => $other->id,
        'permission_id' => Permission::query()->where('name', 'access_control.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ]);

    expect($other->hasPermission('access_control.manage'))->toBeTrue();
    $this->actingAs($other)->get(route('super-admin.access-control'))->assertOk();
});

test('a revoked permission is denied on the very next request', function () {
    $cashier = accessControlUser('cashier', $this->branch);
    $this->actingAs($cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history'))
        ->assertOk();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $cashier), ['overrides' => ['transactions.view' => 'deny']]);

    $this->actingAs($cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history'))
        ->assertForbidden();
});

test('the rbac seeder never resets a live role baseline or custom access', function () {
    $juan = accessControlUser('cashier', $this->branch);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'cashier'), ['permissions' => ['pos.access', 'reports.view']]);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'owner'), ['permissions' => ['reports.view']]);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), ['overrides' => ['transactions.view' => 'allow']]);

    $this->seed(RbacSeeder::class);

    expect(roleBaseline('cashier'))->toBe(['pos.access', 'qr_orders.access', 'reports.view'])
        ->and(roleBaseline('owner'))->toBe(['reports.view'])
        ->and(roleBaseline('cashier_kitchen'))->toBe(['pos.access', 'qr_orders.access', 'kitchen.access', 'customer_display.launch', 'reports.view'])
        ->and(roleBaseline('super_admin'))->toBe(PermissionCatalog::names())
        ->and($juan->hasPermission('transactions.view'))->toBeTrue();
});

test('the rbac seeder completes super admin and grants a newly added permission to its default roles', function () {
    $permissionId = Permission::query()->where('name', 'reports.view')->value('id');
    DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
    Permission::query()->whereKey($permissionId)->delete();

    $this->seed(RbacSeeder::class);

    expect(roleBaseline('super_admin'))->toBe(PermissionCatalog::names())
        ->and(roleBaseline('owner'))->toContain('reports.view')
        ->and(roleBaseline('cashier'))->not->toContain('reports.view');
});

test('access audit records never contain credentials', function () {
    $juan = accessControlUser('cashier', $this->branch);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), ['overrides' => ['reports.view' => 'allow']]);

    $audit = AuditLog::query()->where('action', 'access.user_override_updated')->sole();
    $serialized = json_encode([$audit->before, $audit->after, $audit->metadata]);

    expect($serialized)->not->toContain($juan->password)
        ->and($serialized)->not->toContain('remember_token');
});
