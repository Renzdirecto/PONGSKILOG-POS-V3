<?php

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;

test('all frozen roles and lean permissions are seeded', function () {
    $this->seed(RbacSeeder::class);

    expect(Role::query()->orderBy('name')->pluck('name')->all())->toBe([
        'cashier',
        'cashier_kitchen',
        'kitchen_staff',
        'owner',
        'super_admin',
    ]);
    expect(DB::table('permissions')->orderBy('name')->pluck('name')->all())->toBe([
        'access_control.manage',
        'audit.view',
        'customer_display.launch',
        'inventory.manage',
        'kitchen.access',
        'operations.manage',
        'pos.access',
        'products.manage',
        'qr_orders.access',
        'reports.view',
        'settings.manage',
        'staff.manage',
        'store.open_close',
        'store_expenses.manage',
        'transactions.view',
        'void_orders.manage',
    ]);
});

test('rerunning the seeder does not duplicate rbac records', function () {
    $this->seed(RbacSeeder::class);
    $this->seed(RbacSeeder::class);

    expect(Role::query()->count())->toBe(5);
    expect(DB::table('permissions')->count())->toBe(16);
    expect(DB::table('role_permissions')->count())->toBe(37);
});

test('cashier receives only operational permissions', function () {
    $this->seed(RbacSeeder::class);

    $permissions = Role::query()
        ->where('name', 'cashier')
        ->firstOrFail()
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($permissions)->toBe([
        'pos.access',
        'qr_orders.access',
        'store.open_close',
        'store_expenses.manage',
        'transactions.view',
    ]);
});

test('kitchen staff receives only kitchen permissions', function () {
    $this->seed(RbacSeeder::class);

    $permissions = Role::query()
        ->where('name', 'kitchen_staff')
        ->firstOrFail()
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($permissions)->toBe([
        'customer_display.launch',
        'kitchen.access',
    ]);
});

test('cashier kitchen receives the union of cashier and kitchen permissions', function () {
    $this->seed(RbacSeeder::class);

    $permissions = Role::query()
        ->where('name', 'cashier_kitchen')
        ->firstOrFail()
        ->permissions()
        ->orderBy('name')
        ->pluck('name')
        ->all();

    expect($permissions)->toBe([
        'customer_display.launch',
        'kitchen.access',
        'pos.access',
        'qr_orders.access',
        'store.open_close',
        'store_expenses.manage',
        'transactions.view',
    ]);
});

test('owner receives management permissions without super admin controls', function () {
    $this->seed(RbacSeeder::class);

    $owner = Role::query()->where('name', 'owner')->firstOrFail();
    $permissions = $owner->permissions()->orderBy('name')->pluck('name')->all();

    expect($permissions)->toBe([
        'inventory.manage',
        'operations.manage',
        'products.manage',
        'reports.view',
        'settings.manage',
        'staff.manage',
        'transactions.view',
    ]);
    expect($owner->permissions()->whereIn('name', [
        'audit.view',
        'void_orders.manage',
        'access_control.manage',
    ])->exists())->toBeFalse();
});

test('super admin receives every seeded permission', function () {
    $this->seed(RbacSeeder::class);

    $superAdmin = Role::query()->where('name', 'super_admin')->firstOrFail();

    expect($superAdmin->permissions()->count())->toBe(16);
    expect($superAdmin->permissions()->pluck('name')->sort()->values()->all())
        ->toBe(DB::table('permissions')->pluck('name')->sort()->values()->all());
});

test('user role and permission helpers resolve assigned role permissions', function () {
    $this->seed(RbacSeeder::class);

    $user = User::factory()->create();
    $cashier = Role::query()->where('name', 'cashier')->firstOrFail();
    $user->roles()->attach($cashier);

    expect($user->hasRole('cashier'))->toBeTrue();
    expect($user->hasRole('owner'))->toBeFalse();
    expect($user->hasPermission('pos.access'))->toBeTrue();
});

test('a user without a permission receives a false result', function () {
    $this->seed(RbacSeeder::class);

    $user = User::factory()->create();
    $kitchenStaff = Role::query()->where('name', 'kitchen_staff')->firstOrFail();
    $user->roles()->attach($kitchenStaff);

    expect($user->hasPermission('pos.access'))->toBeFalse();
});
