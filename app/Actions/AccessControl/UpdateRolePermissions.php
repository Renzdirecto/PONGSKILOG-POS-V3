<?php

namespace App\Actions\AccessControl;

use App\Actions\Audit\AuditRecorder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\AdminNotifier;
use App\Support\PermissionCatalog;
use App\Support\StaffRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateRolePermissions
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Replace one editable Role baseline (Owner, Cashier or Kitchen Staff) with the submitted permissions. Only catalog
     * permissions inside the Role's grant envelope are accepted; locked baseline entries are kept as they are, QR
     * Orders follows POS, and Cashier + Kitchen is re-derived from Cashier ∪ Kitchen Staff in the same transaction.
     * An unchanged submission is a no-op (no audit, no notification), so repeated submits are safe.
     *
     * @param  list<string>  $permissions
     * @return bool whether the baseline changed
     */
    public function execute(User $actor, string $roleName, array $permissions): bool
    {
        return DB::transaction(function () use ($actor, $roleName, $permissions): bool {
            $actor = AccessControlActor::resolve($actor);

            if ($roleName === PermissionCatalog::SUPER_ADMIN) {
                throw ValidationException::withMessages(['role' => 'Super Admin is locked to full access.']);
            }
            if ($roleName === PermissionCatalog::DERIVED_ROLE) {
                throw ValidationException::withMessages(['role' => 'Cashier + Kitchen is derived from the Cashier and Kitchen Staff roles.']);
            }
            if (! in_array($roleName, PermissionCatalog::EDITABLE_ROLES, true)) {
                throw ValidationException::withMessages(['role' => 'Choose a role that can be configured.']);
            }

            $submitted = array_values(array_unique($permissions));
            foreach ($submitted as $permission) {
                if (! PermissionCatalog::isGrantable($roleName, $permission)) {
                    throw ValidationException::withMessages([
                        'permissions' => PermissionCatalog::exists($permission)
                            ? PermissionCatalog::label($permission).': '.PermissionCatalog::lockReason($roleName, $permission)
                            : 'Choose permissions from the list only.',
                    ]);
                }
            }

            /** Roles are locked in id order so concurrent baseline edits and the Cashier + Kitchen derivation serialize. */
            $involved = $roleName === 'owner' ? [$roleName] : [...PermissionCatalog::DERIVED_FROM, PermissionCatalog::DERIVED_ROLE];
            $roles = Role::query()->whereIn('name', $involved)->orderBy('id')->lockForUpdate()->get()->keyBy('name');
            $role = $roles->get($roleName) ?? throw ValidationException::withMessages(['role' => 'This role has not been seeded.']);

            $before = $this->baseline($role);
            $locked = array_values(array_filter($before, fn (string $permission): bool => ! PermissionCatalog::isGrantable($roleName, $permission)));
            $after = PermissionCatalog::withQrFollowingPos([...$locked, ...$submitted]);

            if ($after === $before) {
                return false;
            }

            $permissionIds = Permission::query()->whereIn('name', $after)->pluck('id', 'name');
            if ($permissionIds->count() !== count($after)) {
                throw ValidationException::withMessages(['permissions' => 'A permission in this baseline has not been seeded.']);
            }
            $role->permissions()->sync($permissionIds->values()->all());

            $derived = null;
            if (in_array($roleName, PermissionCatalog::DERIVED_FROM, true)) {
                $derivedRole = $roles->get(PermissionCatalog::DERIVED_ROLE);
                if ($derivedRole instanceof Role) {
                    $derivedBefore = $this->baseline($derivedRole);
                    $derivedAfter = PermissionCatalog::union(array_map(
                        fn (string $source): array => $this->baseline($roles->get($source) ?? throw ValidationException::withMessages(['role' => 'A source role has not been seeded.'])),
                        PermissionCatalog::DERIVED_FROM,
                    ));
                    $derivedRole->permissions()->sync(Permission::query()->whereIn('name', $derivedAfter)->pluck('id')->all());
                    $derived = ['before' => $derivedBefore, 'after' => $derivedAfter];
                }
            }

            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'access_control',
                action: 'access.role_permissions_updated',
                auditableType: Role::class,
                auditableId: (string) $role->id,
                before: ['role' => $roleName, 'permissions' => $before],
                after: ['role' => $roleName, 'permissions' => $after],
                metadata: [
                    'role_label' => StaffRoles::label($roleName),
                    'added' => array_values(array_diff($after, $before)),
                    'removed' => array_values(array_diff($before, $after)),
                    'derived_cashier_kitchen' => $derived,
                ],
            );

            $added = array_map(PermissionCatalog::label(...), array_values(array_diff($after, $before)));
            $removed = array_map(PermissionCatalog::label(...), array_values(array_diff($before, $after)));
            AdminNotifier::superAdmins(new AdminAlert(
                'access',
                StaffRoles::label($roleName).' role access changed',
                $actor->name.' changed the '.StaffRoles::label($roleName).' baseline.'
                    .($added === [] ? '' : ' Added: '.implode(', ', $added).'.')
                    .($removed === [] ? '' : ' Removed: '.implode(', ', $removed).'.'),
                route('super-admin.access-control', ['role' => $roleName], false),
            ), except: $actor);

            return true;
        });
    }

    /** @return list<string> */
    private function baseline(Role $role): array
    {
        return PermissionCatalog::ordered($role->permissions()->pluck('permissions.name')->all());
    }
}
