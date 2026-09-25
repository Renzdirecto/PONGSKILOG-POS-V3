<?php

namespace App\Actions\AccessControl;

use App\Actions\Audit\AuditRecorder;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\AccessRealtime;
use App\Support\AdminNotifier;
use App\Support\CustomRoles;
use App\Support\PermissionCatalog;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateCustomRole
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Save one active Custom Role: display name, scope and the complete permission baseline. The Role row is locked
     * FOR UPDATE first, so two admins saving the same role serialize and the result is always one complete submitted
     * baseline, never a merge. Scope may change only while no Staff account holds the role (Staff assignment takes the
     * same row lock), so an assigned role never silently moves its users between Branch and business-wide access.
     * User overrides are never touched: inheriting users follow the new baseline on their next request.
     *
     * @param  list<string>  $permissions
     * @return bool whether anything changed
     */
    public function execute(User $actor, Role $role, string $label, string $scope, array $permissions): bool
    {
        try {
            return DB::transaction(function () use ($actor, $role, $label, $scope, $permissions): bool {
                $actor = AccessControlActor::resolve($actor);
                $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->first();
                if ($role === null || ! $role->isCustom()) {
                    throw ValidationException::withMessages(['role' => 'System roles are not edited here.']);
                }
                if ($role->isArchived()) {
                    throw ValidationException::withMessages(['role' => 'This custom role is archived.']);
                }

                $label = (string) CustomRoles::normalizeLabel($label);
                CustomRoles::validateLabel($label, $role->id);
                if ($scope !== $role->scope && CustomRoles::assignedCount($role) > 0) {
                    throw ValidationException::withMessages([
                        'scope' => 'The access scope of an assigned role cannot change. Reassign its staff first, or create a new role.',
                    ]);
                }
                $after = CustomRoles::baseline($scope, $permissions);

                $permissionsBefore = CustomRoles::permissionsOf($role);
                $before = CustomRoles::snapshot($role, $permissionsBefore);
                $detailsChanged = $label !== $role->label || $scope !== $role->scope;
                $permissionsChanged = $after !== $permissionsBefore;
                if (! $detailsChanged && ! $permissionsChanged) {
                    return false;
                }

                if ($detailsChanged) {
                    $role->forceFill(['label' => $label, 'scope' => $scope])->save();
                }
                if ($permissionsChanged) {
                    $permissionIds = Permission::query()->whereIn('name', $after)->pluck('id');
                    if ($permissionIds->count() !== count($after)) {
                        throw ValidationException::withMessages(['permissions' => 'A permission in this baseline has not been seeded.']);
                    }
                    $role->permissions()->sync($permissionIds->all());
                }
                $snapshot = CustomRoles::snapshot($role, $after);
                $added = array_values(array_diff($after, $permissionsBefore));
                $removed = array_values(array_diff($permissionsBefore, $after));

                if ($detailsChanged) {
                    $this->audit->record(
                        branch: null,
                        actor: $actor,
                        module: 'access_control',
                        action: 'access.custom_role_updated',
                        auditableType: Role::class,
                        auditableId: (string) $role->id,
                        before: ['label' => $before['label'], 'scope' => $before['scope'], 'scope_label' => $before['scope_label']],
                        after: ['label' => $snapshot['label'], 'scope' => $snapshot['scope'], 'scope_label' => $snapshot['scope_label']],
                        metadata: ['role_id' => $role->id, 'key' => $role->name],
                    );
                }
                if ($permissionsChanged) {
                    $this->audit->record(
                        branch: null,
                        actor: $actor,
                        module: 'access_control',
                        action: 'access.custom_role_permissions_updated',
                        auditableType: Role::class,
                        auditableId: (string) $role->id,
                        before: ['permissions' => $permissionsBefore, 'permission_labels' => $before['permission_labels']],
                        after: ['permissions' => $after, 'permission_labels' => $snapshot['permission_labels']],
                        metadata: [
                            'role_id' => $role->id,
                            'key' => $role->name,
                            'role_label' => $role->displayLabel(),
                            'added' => $added,
                            'removed' => $removed,
                        ],
                    );
                }

                $changes = array_values(array_filter([
                    $label !== $before['label'] ? 'renamed from '.$before['label'] : null,
                    $scope !== $before['scope'] ? 'scope '.PermissionCatalog::SCOPES[$scope] : null,
                    $added === [] ? null : 'added '.implode(', ', array_map(PermissionCatalog::label(...), $added)),
                    $removed === [] ? null : 'removed '.implode(', ', array_map(PermissionCatalog::label(...), $removed)),
                ]));
                AdminNotifier::superAdmins(new AdminAlert(
                    'access',
                    'Custom role '.$role->displayLabel().' changed',
                    $actor->name.' changed the '.$role->displayLabel().' role: '.implode('; ', $changes).'.',
                    route('super-admin.access-control', ['role' => $role->name], false),
                ), except: $actor);
                /** Accounts holding the Role revalidate their access; Staff pages show the new Role name. */
                $members = AccessRealtime::userIdsWithRoles([(int) $role->id]);
                AccessRealtime::rolesChanged([(int) $role->id]);
                AccessRealtime::accessControlChanged('custom_role.updated');
                AccessRealtime::staffChanged(AccessRealtime::branchIdsOf($members));

                return true;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['label' => 'A role with this name already exists. Choose a different name.']);
        }
    }
}
