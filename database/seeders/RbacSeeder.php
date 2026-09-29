<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\PermissionCatalog;
use App\Support\StaffRoles;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class RbacSeeder extends Seeder
{
    /**
     * Seeds the canonical Roles and Permissions for fresh installs and tests without ever erasing a live Access Control
     * configuration on a rerun:
     *
     * - a Role receives its catalog defaults only when the Role itself is created by this run;
     * - a Permission that is new in this run is granted to the Roles whose defaults include it;
     * - existing Role ↔ Permission pairs are never removed or re-added, so edited baselines survive;
     * - Super Admin is always completed to every Permission (locked full access);
     * - Cashier + Kitchen is always re-derived as the union of the Cashier and Kitchen Staff baselines;
     * - System role metadata (label, system flag, scope) is kept canonical. Custom Roles created in Access Control are
     *   never read, renamed, archived, re-permissioned or reassigned here.
     */
    public function run(): void
    {
        DB::transaction(function (): void {
            $newPermissions = [];
            foreach (PermissionCatalog::names() as $permissionName) {
                $permission = Permission::query()->firstOrCreate(['name' => $permissionName]);
                if ($permission->wasRecentlyCreated) {
                    $newPermissions[] = $permissionName;
                }
            }
            $permissionIds = Permission::query()->pluck('id', 'name');

            foreach (PermissionCatalog::ROLES as $roleName) {
                $role = Role::query()->firstOrCreate(['name' => $roleName]);
                $role->forceFill([
                    'label' => StaffRoles::LABELS[$roleName],
                    'is_system' => true,
                    'scope' => StaffRoles::SYSTEM_SCOPES[$roleName],
                    'archived_at' => null,
                ])->save();
                $defaults = PermissionCatalog::defaultsFor($roleName);
                $grant = $role->wasRecentlyCreated ? $defaults : array_values(array_intersect($defaults, $newPermissions));

                if ($grant !== []) {
                    $role->permissions()->syncWithoutDetaching($permissionIds->only($grant)->values()->all());
                }
            }

            $superAdmin = Role::query()->where('name', PermissionCatalog::SUPER_ADMIN)->sole();
            $superAdmin->permissions()->syncWithoutDetaching($permissionIds->only(PermissionCatalog::names())->values()->all());

            $derived = Role::query()->where('name', PermissionCatalog::DERIVED_ROLE)->sole();
            $sources = Role::query()->whereIn('name', PermissionCatalog::DERIVED_FROM)->with('permissions:id')->get();
            $derived->permissions()->sync($sources->flatMap(fn (Role $role) => $role->permissions->pluck('id'))->unique()->values()->all());
        });
    }
}
