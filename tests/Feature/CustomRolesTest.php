<?php

use App\Enums\PermissionOverrideEffect;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\ActiveBranchContext;
use App\Support\CustomRoles;
use App\Support\PermissionCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->main = Branch::factory()->create(['name' => 'Main', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave', 'code' => 'QAVE']);
    $this->superAdmin = customRoleUser('super_admin', name: 'Control Admin');
    $this->otherAdmin = customRoleUser('super_admin', name: 'Second Admin');
});

/** @param list<Branch> $branches */
function customRoleUser(string $role, array $branches = [], string $name = 'Staff Member', bool $active = true): User
{
    $user = User::factory()->create(['name' => $name, 'is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    foreach ($branches as $branch) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @param list<string> $permissions */
function createCustomRole(User $actor, string $label, string $scope, array $permissions): Role
{
    test()->actingAs($actor)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => $label, 'scope' => $scope, 'permissions' => $permissions])
        ->assertSessionHasNoErrors();

    return Role::query()->whereNull('archived_at')->whereRaw('LOWER(label) = ?', [mb_strtolower((string) CustomRoles::normalizeLabel($label))])->sole();
}

/** @return list<string> */
function customRoleBaseline(Role $role): array
{
    return PermissionCatalog::ordered($role->permissions()->pluck('permissions.name')->all());
}

/** @param list<string> $branchIds */
function assignRole(User $actor, User $staff, string $role, array $branchIds = []): TestResponse
{
    return test()->actingAs($actor)->put(route('super-admin.staff.update', $staff), [
        'name' => $staff->name,
        'email' => $staff->email,
        'role' => $role,
        'branch_ids' => $branchIds,
        'is_active' => true,
    ]);
}

function branchSupervisor(User $actor): Role
{
    return createCustomRole($actor, 'Branch Supervisor', 'branch', ['pos.access', 'transactions.view', 'reports.view']);
}

test('super admin creates a branch custom role with a stable key, qr following pos, an audit and a notification', function () {
    $role = createCustomRole($this->superAdmin, '  Branch   Supervisor ', 'branch', ['pos.access', 'transactions.view', 'reports.view']);

    expect($role->name)->toBe('custom_'.$role->id)
        ->and($role->label)->toBe('Branch Supervisor')
        ->and($role->is_system)->toBeFalse()
        ->and($role->scope)->toBe('branch')
        ->and(customRoleBaseline($role))->toBe(['pos.access', 'qr_orders.access', 'transactions.view', 'reports.view']);

    $audit = AuditLog::query()->where('action', 'access.custom_role_created')->sole();
    expect($audit->user_id)->toBe($this->superAdmin->id)
        ->and($audit->after['key'])->toBe($role->name)
        ->and($audit->after['label'])->toBe('Branch Supervisor')
        ->and($audit->after['scope'])->toBe('branch')
        ->and($audit->after['permission_labels'])->toBe(['POS', 'QR Orders', 'Transactions', 'Reports']);

    expect($this->otherAdmin->notifications()->sole()->data['title'])->toBe('Custom role Branch Supervisor created')
        ->and($this->superAdmin->notifications()->count())->toBe(0);
});

test('super admin creates a business-wide custom role with management permissions', function () {
    $role = createCustomRole($this->superAdmin, 'Reports Staff', 'business', ['reports.view', 'transactions.view', 'inventory.manage']);

    expect($role->scope)->toBe('business')
        ->and($role->isBusinessWide())->toBeTrue()
        ->and(customRoleBaseline($role))->toBe(['transactions.view', 'reports.view', 'inventory.manage']);
});

test('only an active super admin may create, edit or archive custom roles', function (string $role) {
    $actor = customRoleUser($role, $role === 'owner' ? [] : [$this->main]);
    $existing = branchSupervisor($this->superAdmin);
    $payload = ['label' => 'Sneaky', 'scope' => 'branch', 'permissions' => []];

    $this->actingAs($actor)->post(route('super-admin.access-control.custom-roles.store'), $payload)->assertForbidden();
    $this->actingAs($actor)->put(route('super-admin.access-control.custom-roles.update', $existing), $payload)->assertForbidden();
    $this->actingAs($actor)->post(route('super-admin.access-control.custom-roles.archive', $existing))->assertForbidden();
    expect(Role::query()->where('label', 'Sneaky')->exists())->toBeFalse()
        ->and($existing->fresh()->archived_at)->toBeNull();
})->with(['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen']);

test('an inactive super admin cannot create a custom role', function () {
    $inactive = customRoleUser('super_admin', active: false);

    $this->actingAs($inactive)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => 'Ghost', 'scope' => 'branch', 'permissions' => []]);

    expect(Role::query()->where('label', 'Ghost')->exists())->toBeFalse();
});

test('custom role names are required, bounded and plain', function (mixed $label, string $message) {
    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => $label, 'scope' => 'branch', 'permissions' => []])
        ->assertInvalid(['label' => $message]);
})->with([
    'missing' => ['   ', 'Role name is required.'],
    'too long' => [str_repeat('a', CustomRoles::LABEL_MAX + 1), 'Use at most'],
    'markup' => ['<b>Boss</b>', 'Use letters, numbers'],
    'not a string' => [['Boss'], 'must be a string'],
]);

test('custom role names are unique among active roles ignoring case, system names included', function () {
    branchSupervisor($this->superAdmin);

    foreach (['branch supervisor', 'BRANCH  SUPERVISOR', 'owner', 'Super Admin'] as $label) {
        $this->actingAs($this->superAdmin)
            ->post(route('super-admin.access-control.custom-roles.store'), ['label' => $label, 'scope' => 'business', 'permissions' => []])
            ->assertInvalid(['label' => 'already exists']);
    }
    expect(Role::query()->where('is_system', false)->count())->toBe(1);
});

test('an archived name can be reused by a new role', function () {
    $old = createCustomRole($this->superAdmin, 'Night Shift', 'branch', ['pos.access']);
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.archive', $old))->assertSessionHasNoErrors();

    $new = createCustomRole($this->superAdmin, 'night shift', 'branch', ['kitchen.access']);

    expect($new->id)->not->toBe($old->id)
        ->and($old->fresh()->isArchived())->toBeTrue();
});

test('control permissions can never be granted to a custom role', function (string $scope, string $permission) {
    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => 'Escalator', 'scope' => $scope, 'permissions' => ['reports.view', $permission]])
        ->assertInvalid(['permissions' => 'Super Admin only.']);

    expect(Role::query()->where('label', 'Escalator')->exists())->toBeFalse();
})->with(['branch', 'business'])->with(PermissionCatalog::SUPER_ADMIN_ONLY);

test('a branch custom role cannot hold business-wide permissions and a business role cannot hold branch operations', function (string $scope, string $permission) {
    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => 'Mixed', 'scope' => $scope, 'permissions' => [$permission]])
        ->assertInvalid(['permissions']);

    expect(Role::query()->where('label', 'Mixed')->exists())->toBeFalse();
})->with([
    'branch + products' => ['branch', 'products.manage'],
    'branch + inventory' => ['branch', 'inventory.manage'],
    'branch + staff' => ['branch', 'staff.manage'],
    'branch + settings' => ['branch', 'settings.manage'],
    'business + pos' => ['business', 'pos.access'],
    'business + kitchen' => ['business', 'kitchen.access'],
    'business + store' => ['business', 'store.open_close'],
    'business + expenses' => ['business', 'store_expenses.manage'],
    'business + display' => ['business', 'customer_display.launch'],
]);

test('forged scopes and permission names are rejected, never ignored', function (array $payload, string $field) {
    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => 'Forged', 'scope' => 'branch', 'permissions' => [], ...$payload])
        ->assertInvalid([$field]);

    expect(Role::query()->where('label', 'Forged')->exists())->toBeFalse();
})->with([
    'unknown scope' => [['scope' => 'everything'], 'scope'],
    'super admin scope' => [['scope' => 'super_admin'], 'scope'],
    'unknown permission' => [['permissions' => ['root.access']], 'permissions.0'],
    'qr orders without pos' => [['permissions' => ['qr_orders.access']], 'permissions'],
    'permissions not a list' => [['permissions' => 'pos.access'], 'permissions'],
]);

test('system roles cannot be archived or edited through the custom role builder', function (string $name) {
    $system = Role::query()->where('name', $name)->sole();
    $before = customRoleBaseline($system);

    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.archive', $system))
        ->assertInvalid(['role' => 'System roles cannot be archived or deleted.']);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $system), ['label' => 'Renamed', 'scope' => 'branch', 'permissions' => []])
        ->assertInvalid(['role' => 'System roles are not edited here.']);

    $system->refresh();
    expect($system->archived_at)->toBeNull()
        ->and($system->displayLabel())->not->toBe('Renamed')
        ->and(customRoleBaseline($system))->toBe($before);
})->with(PermissionCatalog::ROLES);

test('super admin stays locked full access and cannot become an editable role', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.roles.update', 'super_admin'), ['permissions' => []])
        ->assertInvalid(['role']);

    $this->actingAs($this->superAdmin)
        ->get(route('super-admin.access-control'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles): bool => collect($roles)->firstWhere('name', 'super_admin')['kind'] === 'locked'
                && collect($roles)->firstWhere('name', 'super_admin')['permissions'] === PermissionCatalog::names()));
});

test('editing a custom role saves the whole baseline atomically and changes inheriting users immediately', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);
    $pedro = customRoleUser($role->name, [$this->main]);

    expect($pedro->hasPermission('reports.view'))->toBeTrue();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Branch Supervisor', 'scope' => 'branch', 'permissions' => ['pos.access', 'kitchen.access']])
        ->assertSessionHasNoErrors();

    expect(customRoleBaseline($role))->toBe(['pos.access', 'qr_orders.access', 'kitchen.access'])
        ->and($pedro->hasPermission('reports.view'))->toBeFalse()
        ->and($juan->hasPermission('kitchen.access'))->toBeTrue();

    $audit = AuditLog::query()->where('action', 'access.custom_role_permissions_updated')->sole();
    expect($audit->metadata['added'])->toBe(['kitchen.access'])
        ->and($audit->metadata['removed'])->toBe(['transactions.view', 'reports.view'])
        ->and(AuditLog::query()->where('action', 'access.custom_role_updated')->exists())->toBeFalse();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Branch Supervisor', 'scope' => 'branch', 'permissions' => ['pos.access', 'kitchen.access', 'products.manage']])
        ->assertInvalid(['permissions']);
    expect(customRoleBaseline($role))->toBe(['pos.access', 'qr_orders.access', 'kitchen.access']);
});

test('renaming keeps the role key, the assignments and the permissions', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Shift Lead', 'scope' => 'branch', 'permissions' => ['pos.access', 'transactions.view', 'reports.view']])
        ->assertSessionHasNoErrors();

    $role->refresh();
    expect($role->name)->toBe('custom_'.$role->id)
        ->and($role->label)->toBe('Shift Lead')
        ->and($juan->roles()->sole()->is($role))->toBeTrue()
        ->and($juan->hasPermission('reports.view'))->toBeTrue();
    $audit = AuditLog::query()->where('action', 'access.custom_role_updated')->sole();
    expect($audit->before['label'])->toBe('Branch Supervisor')
        ->and($audit->after['label'])->toBe('Shift Lead');
});

test('the scope of an assigned custom role cannot change, but an unassigned one can', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Branch Supervisor', 'scope' => 'business', 'permissions' => ['reports.view']])
        ->assertInvalid(['scope' => 'cannot change']);
    expect($role->fresh()->scope)->toBe('branch')
        ->and($juan->hasBusinessWideScope())->toBeFalse();

    $juan->roles()->detach();
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Branch Supervisor', 'scope' => 'business', 'permissions' => ['reports.view']])
        ->assertSessionHasNoErrors();
    expect($role->fresh()->scope)->toBe('business');
});

test('super admin assigns a branch custom role with an explicit branch and the change resets custom access', function () {
    $role = branchSupervisor($this->superAdmin);
    $cashier = customRoleUser('cashier', [$this->main]);
    UserPermissionOverride::query()->create([
        'user_id' => $cashier->id,
        'permission_id' => Permission::query()->where('name', 'kitchen.access')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    assignRole($this->superAdmin, $cashier, $role->name)->assertInvalid(['branch_ids' => 'Choose at least one active Branch for this role.']);
    assignRole($this->superAdmin, $cashier, $role->name, [$this->qave->id])->assertSessionHasNoErrors();

    expect($cashier->roles()->pluck('name')->all())->toBe([$role->name])
        ->and($cashier->branches()->pluck('code')->all())->toBe(['QAVE'])
        ->and(UserPermissionOverride::query()->where('user_id', $cashier->id)->exists())->toBeFalse();
    $audit = AuditLog::query()->where('action', 'staff.role_changed')->sole();
    expect($audit->before['role_label'])->toBe('Cashier')
        ->and($audit->after['role_label'])->toBe('Branch Supervisor')
        ->and($audit->after['branch_access'])->toBe('assigned')
        ->and($audit->metadata['custom_access_reset'])->toBe(['kitchen.access' => 'allow']);
});

test('a business-wide custom role takes no branch assignments and clears them on the way in', function () {
    $role = createCustomRole($this->superAdmin, 'Area Manager', 'business', ['reports.view']);
    $cashier = customRoleUser('cashier', [$this->main]);

    assignRole($this->superAdmin, $cashier, $role->name, [$this->main->id])
        ->assertInvalid(['branch_ids' => 'does not take Branch assignments']);
    assignRole($this->superAdmin, $cashier, $role->name)->assertSessionHasNoErrors();

    expect($cashier->roles()->pluck('name')->all())->toBe([$role->name])
        ->and($cashier->branches()->count())->toBe(0)
        ->and($cashier->hasBusinessWideScope())->toBeTrue();
});

test('moving between custom roles and back to a system role resets custom access each time', function () {
    $a = branchSupervisor($this->superAdmin);
    $b = createCustomRole($this->superAdmin, 'Kitchen Lead', 'branch', ['kitchen.access']);
    $staff = customRoleUser($a->name, [$this->main]);
    $allow = fn () => UserPermissionOverride::query()->create([
        'user_id' => $staff->id,
        'permission_id' => Permission::query()->where('name', 'customer_display.launch')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);

    $allow();
    assignRole($this->superAdmin, $staff, $b->name, [$this->main->id])->assertSessionHasNoErrors();
    expect(UserPermissionOverride::query()->where('user_id', $staff->id)->count())->toBe(0);

    $allow();
    assignRole($this->superAdmin, $staff, 'kitchen_staff', [$this->main->id])->assertSessionHasNoErrors();
    expect(UserPermissionOverride::query()->where('user_id', $staff->id)->count())->toBe(0)
        ->and($staff->roles()->pluck('name')->all())->toBe(['kitchen_staff']);
});

test('the owner is never offered and can never assign a custom role or manage its accounts', function () {
    $role = branchSupervisor($this->superAdmin);
    $owner = customRoleUser('owner');
    $cashier = customRoleUser('cashier', [$this->main]);
    $supervisor = customRoleUser($role->name, [$this->main]);

    $this->actingAs($owner)->get(route('staff.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('roles', fn ($roles): bool => collect($roles)->pluck('name')->all() === ['cashier', 'kitchen_staff', 'cashier_kitchen'])
            ->where('staff.data', fn ($rows): bool => collect($rows)->pluck('id')->doesntContain($supervisor->id)));

    $this->actingAs($owner)->put(route('staff.update', $cashier), [
        'name' => $cashier->name, 'email' => $cashier->email, 'role' => $role->name, 'branch_ids' => [$this->main->id], 'is_active' => true,
    ])->assertInvalid(['role' => 'Choose a valid role.']);
    $this->actingAs($owner)->post(route('staff.store'), [
        'employee_id' => '09252601', 'name' => 'New Lead', 'email' => 'lead@pongskilog.test', 'password' => 'Temporary-Pass-42',
        'password_confirmation' => 'Temporary-Pass-42', 'role' => $role->name, 'branch_ids' => [$this->main->id],
    ])->assertInvalid(['role' => 'Choose a valid role.']);
    $this->actingAs($owner)->put(route('staff.update', $supervisor), [
        'name' => $supervisor->name, 'email' => $supervisor->email, 'role' => 'cashier', 'branch_ids' => [$this->main->id], 'is_active' => true,
    ])->assertForbidden();

    expect($cashier->roles()->pluck('name')->all())->toBe(['cashier'])
        ->and(User::query()->where('email', 'lead@pongskilog.test')->exists())->toBeFalse();
});

test('super admin sees active custom roles as staff role options and can create an account with one', function () {
    $role = branchSupervisor($this->superAdmin);

    $this->actingAs($this->superAdmin)->get(route('super-admin.staff.index'))
        ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles): bool => collect($roles)->contains(
            fn (array $option): bool => $option === ['name' => $role->name, 'label' => 'Branch Supervisor', 'business_wide' => false, 'custom' => true],
        )));

    $this->actingAs($this->superAdmin)->post(route('super-admin.staff.store'), [
        'employee_id' => '09252602', 'name' => 'Maria Santos', 'email' => 'maria@pongskilog.test', 'password' => 'Temporary-Pass-42',
        'password_confirmation' => 'Temporary-Pass-42', 'role' => $role->name, 'branch_ids' => [$this->main->id],
    ])->assertSessionHasNoErrors();

    $maria = User::query()->where('email', 'maria@pongskilog.test')->sole();
    expect($maria->roles()->pluck('name')->all())->toBe([$role->name])
        ->and(AuditLog::query()->where('action', 'staff.created')->sole()->after['role_label'])->toBe('Branch Supervisor');
});

test('archiving is blocked while the role is assigned and an archived role cannot be newly assigned', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);
    $cashier = customRoleUser('cashier', [$this->main]);

    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.archive', $role))
        ->assertInvalid(['role' => 'assigned to 1 staff account']);
    expect($role->fresh()->archived_at)->toBeNull();

    assignRole($this->superAdmin, $juan, 'cashier', [$this->main->id])->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.archive', $role))->assertSessionHasNoErrors();

    expect($role->fresh()->isArchived())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'access.custom_role_archived')->sole()->metadata['label'])->toBe('Branch Supervisor');
    assignRole($this->superAdmin, $cashier, $role->name, [$this->main->id])->assertInvalid(['role']);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Branch Supervisor', 'scope' => 'branch', 'permissions' => []])
        ->assertInvalid(['role' => 'archived']);
    $this->actingAs($this->superAdmin)->get(route('super-admin.staff.index'))
        ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles): bool => collect($roles)->pluck('name')->doesntContain($role->name)));
});

test('custom role baseline plus per-user allow and deny changes only that account and never escapes the scope', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);
    $pedro = customRoleUser($role->name, [$this->main]);
    $maria = customRoleUser($role->name, [$this->main]);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $juan), ['overrides' => ['kitchen.access' => 'allow']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.users.update', $maria), ['overrides' => ['reports.view' => 'deny']])
        ->assertSessionHasNoErrors();

    expect($juan->hasPermission('kitchen.access'))->toBeTrue()
        ->and($pedro->hasPermission('kitchen.access'))->toBeFalse()
        ->and($maria->hasPermission('reports.view'))->toBeFalse()
        ->and($pedro->hasPermission('reports.view'))->toBeTrue();

    foreach (['products.manage', 'inventory.manage', 'access_control.manage', 'audit.view'] as $permission) {
        $this->actingAs($this->superAdmin)
            ->put(route('super-admin.access-control.users.update', $pedro), ['overrides' => [$permission => 'allow']])
            ->assertInvalid(['overrides.'.$permission]);
    }
    expect(UserPermissionOverride::query()->where('user_id', $pedro->id)->exists())->toBeFalse();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Branch Supervisor', 'scope' => 'branch', 'permissions' => ['pos.access', 'transactions.view']])
        ->assertSessionHasNoErrors();
    expect($pedro->hasPermission('reports.view'))->toBeFalse()
        ->and($juan->hasPermission('kitchen.access'))->toBeTrue()
        ->and(UserPermissionOverride::query()->where('user_id', $juan->id)->count())->toBe(1);
});

test('a branch custom role with reports stays on its assigned branch everywhere', function () {
    $mainSession = StoreSession::factory()->for($this->main)->create();
    $qaveSession = StoreSession::factory()->for($this->qave)->create();
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);

    $this->actingAs($juan)->get(route('workspace'))->assertRedirectToRoute('workspaces.cashier');
    $this->actingAs($juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->qave->id])
        ->get(route('workspaces.reports', ['session' => $qaveSession->id, 'date' => 'today']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('auth.roleLabel', 'Branch Supervisor')
            ->where('branchContext.businessWide', false)
            ->has('branchContext.selectableBranches', 1)
            ->where('report.scope.code', 'MAIN')
            ->where('report.sessions', fn ($sessions): bool => collect($sessions)->pluck('id')->all() === [$mainSession->id])
            ->where('analytics.branches', null));

    $export = $this->actingAs($juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.reports.export'))
        ->assertOk();
    expect($export->getContent())->not->toContain('QAVE');

    $this->actingAs($juan)->put(route('branch-context.update', $this->qave))->assertForbidden();
    $this->actingAs($juan)->get(route('workspaces.owner'))->assertForbidden();
    $this->actingAs($juan)->get(route('super-admin.access-control'))->assertForbidden();
    $this->actingAs($juan)->get(route('workspaces.transactions'))->assertForbidden();

    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret',
        'app_id' => 'test-app', 'options' => ['cluster' => 'mt1', 'useTLS' => true],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();
    $channel = fn (string $name) => ['socket_id' => '123.456', 'channel_name' => $name];
    $this->actingAs($juan)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->main->id.'.reports'))->assertOk();
    $this->actingAs($juan)->postJson('/broadcasting/auth', $channel('private-branch.'.$this->qave->id.'.reports'))->assertForbidden();
    $this->actingAs($juan)->postJson('/broadcasting/auth', $channel('private-reports'))->assertForbidden();
});

test('a branch custom role with pos runs the pos only at its assigned branch', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);

    expect($juan->hasCashierOperationsRole())->toBeTrue()
        ->and($juan->hasOperationalBranchAccess($this->main))->toBeTrue()
        ->and($juan->hasOperationalBranchAccess($this->qave))->toBeFalse()
        ->and($juan->hasBusinessWideScope())->toBeFalse();

    $this->actingAs($juan)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])
        ->get(route('workspaces.cashier'))
        ->assertOk();
});

test('a business-wide custom role reads every branch but never gains cashier operations or control', function () {
    StoreSession::factory()->for($this->main)->create();
    StoreSession::factory()->for($this->qave)->create();
    $role = createCustomRole($this->superAdmin, 'Area Manager', 'business', ['reports.view', 'staff.manage']);
    $manager = customRoleUser($role->name);

    expect($manager->hasBusinessWideScope())->toBeTrue()
        ->and($manager->hasCashierOperationsRole())->toBeFalse();

    $this->actingAs($manager)->get(route('workspace'))->assertRedirectToRoute('workspaces.owner');
    $this->actingAs($manager)->get(route('workspaces.reports'))
        ->assertInertia(fn (Assert $page) => $page->where('report.scope', null)->has('report.sessions', 2));
    $this->actingAs($manager)->get(route('staff.index'))
        ->assertInertia(fn (Assert $page) => $page->where('roles', fn ($roles): bool => collect($roles)->pluck('name')->all() === ['cashier', 'kitchen_staff', 'cashier_kitchen']));

    $this->actingAs($manager)->withSession([ActiveBranchContext::SESSION_KEY => $this->main->id])->get(route('workspaces.cashier'))->assertForbidden();
    $this->actingAs($manager)->get(route('super-admin.access-control'))->assertForbidden();
    $this->actingAs($manager)->get(route('workspaces.audit-trail'))->assertForbidden();
    $this->actingAs($manager)->get(route('workspaces.void-orders'))->assertForbidden();
    $this->actingAs($manager)->get(route('workspaces.super-admin'))->assertForbidden();
});

test('stored metadata never widens a system role and an archived business role gives no scope', function () {
    Role::query()->where('name', 'cashier')->update(['scope' => 'business', 'is_system' => false]);
    $cashier = customRoleUser('cashier', [$this->main]);
    expect($cashier->hasBusinessWideScope())->toBeFalse();

    $role = createCustomRole($this->superAdmin, 'Area Manager', 'business', ['reports.view']);
    $manager = customRoleUser($role->name);
    DB::table('roles')->where('id', $role->id)->update(['archived_at' => now()]);
    expect($manager->hasBusinessWideScope())->toBeFalse();
});

test('access control lists custom roles with their scope, assigned count and members', function () {
    $role = branchSupervisor($this->superAdmin);
    customRoleUser($role->name, [$this->main], 'Juan Dela Cruz');
    $archived = createCustomRole($this->superAdmin, 'Old Role', 'business', []);
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.archive', $archived));

    $this->actingAs($this->superAdmin)
        ->get(route('super-admin.access-control', ['role' => $role->name]))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('filters.role', $role->name)
            ->where('scopeLocks', fn ($locks): bool => str_contains((string) $locks['branch']['products.manage'], 'business-wide')
                && str_contains((string) $locks['business']['pos.access'], 'Branch')
                && $locks['branch']['reports.view'] === null)
            ->where('roles', function ($roles) use ($role, $archived): bool {
                $roles = collect($roles);
                $custom = $roles->firstWhere('name', $role->name);

                return $roles->take(5)->pluck('name')->all() === PermissionCatalog::ROLES
                    && $custom['kind'] === 'custom'
                    && $custom['label'] === 'Branch Supervisor'
                    && $custom['scope'] === 'branch'
                    && $custom['assigned_count'] === 1
                    && $custom['members'][0]['name'] === 'Juan Dela Cruz'
                    && $custom['locks']['audit.view'] === 'Super Admin only.'
                    && $roles->firstWhere('name', $archived->name)['kind'] === 'archived';
            }));
});

test('the rbac seeder never deletes, renames, archives, re-permissions or reassigns custom roles', function () {
    $role = branchSupervisor($this->superAdmin);
    $juan = customRoleUser($role->name, [$this->main]);
    $archived = createCustomRole($this->superAdmin, 'Old Role', 'business', ['reports.view']);
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.archive', $archived));
    $before = Role::query()->orderBy('id')->get(['id', 'name', 'label', 'is_system', 'scope', 'archived_at'])->toArray();

    $this->seed(RbacSeeder::class);

    expect(Role::query()->orderBy('id')->get(['id', 'name', 'label', 'is_system', 'scope', 'archived_at'])->toArray())->toBe($before)
        ->and(customRoleBaseline($role))->toBe(['pos.access', 'qr_orders.access', 'transactions.view', 'reports.view'])
        ->and(customRoleBaseline($archived->fresh()))->toBe(['reports.view'])
        ->and($juan->roles()->pluck('name')->all())->toBe([$role->name]);
});

test('custom role audits and notifications never carry secrets and skip the actor', function () {
    $role = branchSupervisor($this->superAdmin);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.access-control.custom-roles.update', $role), ['label' => 'Shift Lead', 'scope' => 'branch', 'permissions' => ['pos.access']]);
    $this->actingAs($this->superAdmin)->post(route('super-admin.access-control.custom-roles.archive', $role));

    $payload = json_encode(AuditLog::query()->where('module', 'access_control')->get(['before', 'after', 'metadata'])->toArray());
    expect($payload)->not->toContain('password')->not->toContain('token')->not->toContain($this->superAdmin->password)
        ->and($this->superAdmin->notifications()->count())->toBe(0)
        ->and($this->otherAdmin->notifications()->count())->toBe(3);
});
