<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;

class RbacSeeder extends Seeder
{
    private const PERMISSIONS = [
        'pos.access',
        'qr_orders.access',
        'transactions.view',
        'store.open_close',
        'store_expenses.manage',
        'kitchen.access',
        'customer_display.launch',
        'reports.view',
        'products.manage',
        'inventory.manage',
        'staff.manage',
        'settings.manage',
        'audit.view',
        'void_orders.manage',
        'access_control.manage',
    ];

    private const ROLE_PERMISSIONS = [
        'super_admin' => self::PERMISSIONS,
        'owner' => [
            'transactions.view',
            'reports.view',
            'products.manage',
            'inventory.manage',
            'staff.manage',
            'settings.manage',
        ],
        'cashier' => [
            'pos.access',
            'qr_orders.access',
            'transactions.view',
            'store.open_close',
            'store_expenses.manage',
        ],
        'kitchen_staff' => [
            'kitchen.access',
            'customer_display.launch',
        ],
        'cashier_kitchen' => [
            'pos.access',
            'qr_orders.access',
            'transactions.view',
            'store.open_close',
            'store_expenses.manage',
            'kitchen.access',
            'customer_display.launch',
        ],
    ];

    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        foreach (self::PERMISSIONS as $permissionName) {
            Permission::query()->firstOrCreate(['name' => $permissionName]);
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissionNames) {
            $role = Role::query()->firstOrCreate(['name' => $roleName]);
            $permissionIds = Permission::query()
                ->whereIn('name', $permissionNames)
                ->pluck('id');

            $role->permissions()->sync($permissionIds);
        }
    }
}
