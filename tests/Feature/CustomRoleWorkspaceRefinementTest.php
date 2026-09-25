<?php

use App\Enums\KitchenStatus;
use App\Enums\PermissionOverrideEffect;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Ingredient;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Support\ActiveBranchContext;
use App\Support\CustomRoles;
use App\Support\EffectivePermissions;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->travelTo(CarbonImmutable::parse('2026-09-25 09:00', 'Asia/Manila'));
    $this->superAdmin = User::factory()->create(['name' => 'Control Admin']);
    $this->superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
});

/** @param list<string> $permissions */
function refinementRole(string $label, string $scope, array $permissions): Role
{
    test()->actingAs(test()->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => $label, 'scope' => $scope, 'permissions' => $permissions])
        ->assertSessionHasNoErrors();

    return Role::query()->whereNull('archived_at')->whereRaw('LOWER(label) = ?', [mb_strtolower((string) CustomRoles::normalizeLabel($label))])->sole();
}

function refinementUser(Role|string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->roles()->attach($role instanceof Role ? $role : Role::query()->where('name', $role)->sole());

    return $user;
}

test('the owner keeps inventory and operations after the split and opens both workspaces', function () {
    $owner = refinementUser('owner');

    expect(EffectivePermissions::names($owner))->toContain('inventory.manage', 'operations.manage');
    $this->actingAs($owner)->get(route('inventory.index'))->assertOk();
    $this->actingAs($owner)->get(route('operations.plans'))->assertOk();
});

test('inventory and operations are granted independently to a business-wide custom role', function (string $granted, string $allowedRoute, string $deniedRoute) {
    $role = refinementRole('Stock Lead', 'business', [$granted]);
    $lead = refinementUser($role);

    $this->actingAs($lead)->get(route($allowedRoute))->assertOk();
    $this->actingAs($lead)->get(route($deniedRoute))->assertForbidden();
})->with([
    'inventory only' => ['inventory.manage', 'inventory.index', 'operations.plans'],
    'operations only' => ['operations.manage', 'operations.plans', 'inventory.index'],
]);

test('a business-wide role holding only operations lands on the operations workspace', function () {
    $lead = refinementUser(refinementRole('Kitchen Planner', 'business', ['operations.manage']));

    $this->actingAs($lead)->get(route('workspace'))->assertRedirectToRoute('operations.plans');
});

test('a forged operations write without operations access is refused and changes nothing', function () {
    $lead = refinementUser(refinementRole('Stock Lead', 'business', ['inventory.manage']));

    $this->actingAs($lead)->post(route('operations.ingredients.store'), [
        'name' => 'Rice', 'base_unit' => 'g', 'category' => 'Grains',
    ])->assertForbidden();

    expect(Ingredient::query()->count())->toBe(0);
});

test('operations can be granted to a branch custom role, control never', function () {
    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => 'Shift Lead', 'scope' => 'branch', 'permissions' => ['pos.access', 'operations.manage']])
        ->assertSessionHasNoErrors();
    $this->actingAs($this->superAdmin)
        ->post(route('super-admin.access-control.custom-roles.store'), ['label' => 'Shift Auditor', 'scope' => 'branch', 'permissions' => ['operations.manage', 'audit.view']])
        ->assertInvalid(['permissions']);

    expect(Role::query()->where('label', 'Shift Lead')->exists())->toBeTrue()
        ->and(Role::query()->where('label', 'Shift Auditor')->exists())->toBeFalse();
});

test('the split migration copies every existing inventory grant and override to operations exactly once', function () {
    Permission::query()->where('name', 'operations.manage')->delete();
    $inventory = Permission::query()->where('name', 'inventory.manage')->sole();
    $manager = Role::query()->forceCreate(['name' => 'custom_legacy', 'label' => 'Legacy Manager', 'is_system' => false, 'scope' => 'business']);
    $manager->permissions()->attach($inventory);
    $allowed = refinementUser('cashier');
    $denied = refinementUser('owner');
    UserPermissionOverride::query()->forceCreate(['user_id' => $allowed->id, 'permission_id' => $inventory->id, 'effect' => PermissionOverrideEffect::Allow]);
    UserPermissionOverride::query()->forceCreate(['user_id' => $denied->id, 'permission_id' => $inventory->id, 'effect' => PermissionOverrideEffect::Deny]);
    $migration = require database_path('migrations/2026_09_25_082319_split_operations_from_inventory_permission.php');

    $migration->up();
    $migration->up();

    $operationsId = Permission::query()->where('name', 'operations.manage')->sole()->id;
    expect(DB::table('role_permissions')->where('permission_id', $operationsId)->join('roles', 'roles.id', '=', 'role_permissions.role_id')->orderBy('roles.name')->pluck('roles.name')->all())
        ->toBe(['custom_legacy', 'owner', 'super_admin'])
        ->and(EffectivePermissions::overrides($allowed->id)['operations.manage'])->toBe(PermissionOverrideEffect::Allow)
        ->and(EffectivePermissions::overrides($denied->id)['operations.manage'])->toBe(PermissionOverrideEffect::Deny)
        ->and($allowed->hasPermission('operations.manage'))->toBeTrue()
        ->and($denied->hasPermission('operations.manage'))->toBeFalse();
});

test('staff are created and edited with a trimmed position that is audited', function () {
    $branch = Branch::factory()->create();

    $this->actingAs($this->superAdmin)->post(route('super-admin.staff.store'), [
        'employee_id' => '09252601', 'name' => 'Juan Dela Cruz', 'email' => 'juan@pongskilog.test',
        'position' => '  Branch   Supervisor ', 'password' => 'Temporary-Pass-42', 'password_confirmation' => 'Temporary-Pass-42',
        'role' => 'cashier', 'branch_ids' => [$branch->id], 'is_active' => true,
    ])->assertSessionHasNoErrors();
    $juan = User::query()->where('email', 'juan@pongskilog.test')->sole();
    $this->actingAs($this->superAdmin)->put(route('super-admin.staff.update', $juan), [
        'name' => $juan->name, 'email' => $juan->email, 'position' => 'Shift Supervisor', 'role' => 'cashier', 'branch_ids' => [$branch->id], 'is_active' => true,
    ])->assertSessionHasNoErrors();

    expect($juan->fresh()->position)->toBe('Shift Supervisor')
        ->and(AuditLog::query()->where('action', 'staff.created')->sole()->after['position'])->toBe('Branch Supervisor');
    $updated = AuditLog::query()->where('action', 'staff.updated')->sole();
    expect($updated->before['position'])->toBe('Branch Supervisor')
        ->and($updated->after['position'])->toBe('Shift Supervisor');
    $this->actingAs($this->superAdmin)->get(route('super-admin.staff.index', ['search' => 'shift supervisor']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('staff.data', 1)
            ->where('staff.data.0.position', 'Shift Supervisor'));
});

test('a blank position is cleared and an omitted position is kept', function () {
    $branch = Branch::factory()->create();
    $staff = refinementUser('cashier', ['position' => 'Kitchen Lead']);
    $staff->branches()->attach($branch, ['is_active' => true]);
    $payload = ['name' => $staff->name, 'email' => $staff->email, 'role' => 'cashier', 'branch_ids' => [$branch->id], 'is_active' => true];

    $this->actingAs($this->superAdmin)->put(route('super-admin.staff.update', $staff), $payload)->assertSessionHasNoErrors();
    expect($staff->fresh()->position)->toBe('Kitchen Lead');

    $this->actingAs($this->superAdmin)->put(route('super-admin.staff.update', $staff), [...$payload, 'position' => '   '])->assertSessionHasNoErrors();
    expect($staff->fresh()->position)->toBeNull();
});

test('an invalid position is rejected with a readable message', function (string $position, string $message) {
    $branch = Branch::factory()->create();
    $staff = refinementUser('cashier', ['position' => 'Kitchen Lead']);
    $staff->branches()->attach($branch, ['is_active' => true]);

    $this->actingAs($this->superAdmin)->put(route('super-admin.staff.update', $staff), [
        'name' => $staff->name, 'email' => $staff->email, 'position' => $position, 'role' => 'cashier', 'branch_ids' => [$branch->id], 'is_active' => true,
    ])->assertInvalid(['position' => $message]);

    expect($staff->fresh()->position)->toBe('Kitchen Lead');
})->with([
    'too long' => [str_repeat('a', 101), 'Use at most 100 characters for the Position.'],
    'markup' => ['<script>alert(1)</script>', "Use letters, numbers, spaces or & + - / ( ) . ' , only."],
]);

test('a position never grants access', function () {
    $branch = Branch::factory()->create();
    $cashier = refinementUser('cashier', ['position' => 'Super Admin']);
    $cashier->branches()->attach($branch, ['is_active' => true]);
    $plain = refinementUser('cashier');

    expect(EffectivePermissions::names($cashier))->toBe(EffectivePermissions::names($plain));
    $this->actingAs($cashier)->get(route('super-admin.staff.index'))->assertForbidden();
    $this->actingAs($cashier)->get(route('operations.plans'))->assertForbidden();
});

test('the shared identity and the audit trail actor carry the current position', function () {
    $manager = refinementUser(refinementRole('Area Manager', 'business', ['reports.view']), ['name' => 'Shamarra Faustino', 'position' => 'Area Manager']);
    AuditLog::query()->forceCreate([
        'user_id' => $manager->id, 'module' => 'staff', 'action' => 'staff.updated',
        'auditable_type' => User::class, 'auditable_id' => (string) $manager->id, 'created_at' => now(),
    ]);

    $this->actingAs($manager)->get(route('workspaces.owner'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.user.position', 'Area Manager')->where('auth.roleLabel', 'Area Manager'));
    $this->actingAs($this->superAdmin)->get(route('workspaces.audit-trail'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('logs.data.0.actor.name', 'Shamarra Faustino')
            ->where('logs.data.0.actor.position', 'Area Manager'));
});

test('a business-wide custom role with pos sees the real store status at the selected branch', function () {
    $scenario = StoreCloseScenario::create();
    $closed = Branch::factory()->create();
    $manager = refinementUser(refinementRole('Area Manager', 'business', ['pos.access']));

    $this->actingAs($manager)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id])->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('storeContext.isOpen', true)->where('storeContext.branchId', $scenario->branch->id));
    $this->actingAs($manager)->withSession([ActiveBranchContext::SESSION_KEY => $closed->id])->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('storeContext.isOpen', false)->where('storeContext.branchId', $closed->id));
});

test('a business-wide custom role with pos receives ready orders and marks them done like a cashier', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->kitchenStatus($scenario->payNow(1, 'cash'), KitchenStatus::Ready);
    $manager = refinementUser(refinementRole('Area Manager', 'business', ['pos.access']));
    $session = [ActiveBranchContext::SESSION_KEY => $scenario->branch->id];
    config(['broadcasting.default' => 'pusher', 'broadcasting.connections.pusher' => [
        'driver' => 'pusher', 'key' => 'test-key', 'secret' => 'test-secret', 'app_id' => 'test-app', 'options' => ['cluster' => 'ap1'],
    ]]);
    (static function (): void {
        require base_path('routes/channels.php');
    })();

    $this->actingAs($manager)->withSession($session)->get(route('workspaces.cashier'))
        ->assertInertia(fn (Assert $page) => $page->where('readyOrders.0.id', $order->id));
    $this->actingAs($manager)->postJson('/broadcasting/auth', [
        'socket_id' => '123.456', 'channel_name' => 'private-branch.'.$scenario->branch->id.'.pos',
    ])->assertOk();
    $this->actingAs($manager)->withSession($session)
        ->patchJson(route('orders.kitchen-status.update', $order), ['status' => 'done'])
        ->assertOk();

    expect($order->fresh()->kitchen_status)->toBe(KitchenStatus::Done);
});

test('business transactions allow existing pos mutations only while the selected store is open', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->payLater(1);
    $manager = refinementUser(refinementRole('Area Manager', 'business', ['pos.access', 'transactions.view']));
    $session = [ActiveBranchContext::SESSION_KEY => $scenario->branch->id];

    $this->actingAs($manager)->withSession($session)->get(route('workspaces.transactions'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operational', true)
            ->where('transactions.data.0.can_edit', true)
            ->where('transactions.data.0.can_settle', true)
            ->where('transactions.data.0.can_void', true));

    $scenario->session->update(['status' => 'closed', 'closed_at' => now()]);

    $this->actingAs($manager)->withSession($session)->get(route('workspaces.transactions'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('operational', false)
            ->where('transactions.data.0.id', $order->id)
            ->where('transactions.data.0.can_edit', false)
            ->where('transactions.data.0.can_settle', false)
            ->where('transactions.data.0.can_void', false));
    $this->actingAs($manager)->withSession($session)->getJson(route('workspaces.transactions.show', $order))
        ->assertOk()
        ->assertJsonPath('transaction.operational', false)
        ->assertJsonPath('transaction.can_edit', false);
});

test('the backend rejects a custom role transaction mutation after the store closes', function () {
    $scenario = StoreCloseScenario::create();
    $order = $scenario->payLater(1);
    $scenario->authorizeVoids();
    $manager = refinementUser(refinementRole('Area Manager', 'business', ['pos.access', 'transactions.view']));
    $scenario->session->update(['status' => 'closed', 'closed_at' => now()]);
    $http = $this->actingAs($manager)->withSession([ActiveBranchContext::SESSION_KEY => $scenario->branch->id]);

    $http->patchJson(route('pos.transactions.update', $order), [
        'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version, 'order_type' => 'take_out',
        'items' => [['existing_order_item_id' => $order->items()->value('id'), 'product_id' => $scenario->product->id, 'quantity' => 2, 'modifiers' => []]],
    ])->assertJsonValidationErrors(['store']);
    $http->postJson(route('pos.transactions.void', $order), [
        'reason_code' => 'wrong_item', 'authorization_pin' => '1234', 'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version,
    ])->assertJsonValidationErrors(['store']);
    $http->postJson(route('pos.orders.settlements.store', $order), [
        'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '100.00',
    ])->assertJsonValidationErrors(['store']);

    $fresh = $order->fresh();
    expect($fresh->version)->toBe($order->version)
        ->and($fresh->commercial_status)->toBe($order->commercial_status)
        ->and($fresh->payment_status->value)->toBe('unpaid');
});
