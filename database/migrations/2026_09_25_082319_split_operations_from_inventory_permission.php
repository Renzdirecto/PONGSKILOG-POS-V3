<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Owner Operations (Ingredients, Recipes, Ingredient Stock, Pamamalengke, Purchases) moves from `inventory.manage`
     * to its own `operations.manage`. On an existing install every Role baseline and every per-user override that
     * holds `inventory.manage` today receives the same `operations.manage` grant, so nobody gains or loses Operations
     * by the split. A fresh install (no permissions yet) is left to the RbacSeeder defaults.
     */
    public function up(): void
    {
        $inventoryId = DB::table('permissions')->where('name', 'inventory.manage')->value('id');
        if ($inventoryId === null || DB::table('permissions')->where('name', 'operations.manage')->exists()) {
            return;
        }

        $operationsId = DB::table('permissions')->insertGetId([
            'name' => 'operations.manage',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('role_permissions')->insertUsing(
            ['role_id', 'permission_id'],
            DB::table('role_permissions')->where('permission_id', $inventoryId)->select('role_id', DB::raw((int) $operationsId)),
        );
        DB::table('user_permission_overrides')->insertUsing(
            ['user_id', 'permission_id', 'effect', 'created_at', 'updated_at'],
            DB::table('user_permission_overrides')->where('permission_id', $inventoryId)
                ->select('user_id', DB::raw((int) $operationsId), 'effect', 'created_at', 'updated_at'),
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->where('name', 'operations.manage')->delete();
    }
};
