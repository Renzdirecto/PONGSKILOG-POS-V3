<?php

namespace App\Actions\AccessControl;

use App\Actions\Audit\AuditRecorder;
use App\Models\Role;
use App\Models\User;
use App\Notifications\AdminAlert;
use App\Support\AccessRealtime;
use App\Support\AdminNotifier;
use App\Support\CustomRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ArchiveCustomRole
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Archive an unassigned Custom Role. The row and its baseline stay for audit meaning; an archived role is never
     * offered or accepted for Staff assignment, and its name becomes free for a new role. System roles are never
     * archived. The role row lock serializes with Staff assignment, so a role cannot be archived while an account is
     * being moved into it.
     *
     * @return bool whether the role was archived by this call (false if it already was)
     */
    public function execute(User $actor, Role $role): bool
    {
        return DB::transaction(function () use ($actor, $role): bool {
            $actor = AccessControlActor::resolve($actor);
            $role = Role::query()->whereKey($role->getKey())->lockForUpdate()->first();
            if ($role === null || ! $role->isCustom()) {
                throw ValidationException::withMessages(['role' => 'System roles cannot be archived or deleted.']);
            }
            if ($role->isArchived()) {
                return false;
            }
            $assigned = CustomRoles::assignedCount($role);
            if ($assigned > 0) {
                throw ValidationException::withMessages([
                    'role' => $role->displayLabel().' is assigned to '.$assigned.' staff account'.($assigned === 1 ? '' : 's').'. Reassign them in Staff first.',
                ]);
            }

            $permissions = CustomRoles::permissionsOf($role);
            $role->forceFill(['archived_at' => now()])->save();

            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'access_control',
                action: 'access.custom_role_archived',
                auditableType: Role::class,
                auditableId: (string) $role->id,
                before: ['archived' => false],
                after: ['archived' => true],
                metadata: CustomRoles::snapshot($role, $permissions),
            );

            AdminNotifier::superAdmins(new AdminAlert(
                'access',
                'Custom role '.$role->displayLabel().' archived',
                $actor->name.' archived the '.$role->displayLabel().' role. It can no longer be assigned.',
                route('super-admin.access-control', ['tab' => 'roles'], false),
            ), except: $actor);
            AccessRealtime::accessControlChanged('custom_role.archived');
            AccessRealtime::staffChanged();

            return true;
        });
    }
}
