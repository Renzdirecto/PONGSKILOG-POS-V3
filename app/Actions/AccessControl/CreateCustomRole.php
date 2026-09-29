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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CreateCustomRole
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Create a reusable Custom Role (Super Admin only): a unique display name, a Branch or business-wide scope and a
     * baseline inside that scope's grant envelope, with its stable `custom_{id}` key, permissions and the audit record in
     * one transaction. The unique active-name index is the final guard against two admins racing on the same name.
     *
     * @param  list<string>  $permissions
     */
    public function execute(User $actor, string $label, string $scope, array $permissions): Role
    {
        try {
            return DB::transaction(function () use ($actor, $label, $scope, $permissions): Role {
                $actor = AccessControlActor::resolve($actor);
                $label = (string) CustomRoles::normalizeLabel($label);
                CustomRoles::validateLabel($label);
                $baseline = CustomRoles::baseline($scope, $permissions);

                $role = new Role;
                $role->forceFill([
                    'name' => Role::CUSTOM_PREFIX.'pending_'.Str::lower(Str::random(24)),
                    'label' => $label,
                    'is_system' => false,
                    'scope' => $scope,
                ])->save();
                $role->forceFill(['name' => Role::CUSTOM_PREFIX.$role->id])->save();

                $permissionIds = Permission::query()->whereIn('name', $baseline)->pluck('id');
                if ($permissionIds->count() !== count($baseline)) {
                    throw ValidationException::withMessages(['permissions' => 'A permission in this baseline has not been seeded.']);
                }
                $role->permissions()->sync($permissionIds->all());

                $snapshot = CustomRoles::snapshot($role, $baseline);
                $this->audit->record(
                    branch: null,
                    actor: $actor,
                    module: 'access_control',
                    action: 'access.custom_role_created',
                    auditableType: Role::class,
                    auditableId: (string) $role->id,
                    after: $snapshot,
                    metadata: ['role_label' => $role->displayLabel(), 'scope' => $scope],
                );

                AdminNotifier::superAdmins(new AdminAlert(
                    'access',
                    'Custom role '.$role->displayLabel().' created',
                    $actor->name.' created the '.PermissionCatalog::SCOPES[$scope].' role '.$role->displayLabel().': '
                        .($baseline === [] ? 'no permissions yet.' : implode(', ', $snapshot['permission_labels']).'.'),
                    route('super-admin.access-control', ['role' => $role->name], false),
                ), except: $actor);
                AccessRealtime::accessControlChanged('custom_role.created');
                AccessRealtime::staffChanged();

                return $role;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['label' => 'A role with this name was just created. Choose a different name.']);
        }
    }
}
