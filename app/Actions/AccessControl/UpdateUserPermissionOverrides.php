<?php

namespace App\Actions\AccessControl;

use App\Actions\Audit\AuditRecorder;
use App\Enums\PermissionOverrideEffect;
use App\Models\Permission;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Notifications\AdminAlert;
use App\Support\AdminNotifier;
use App\Support\EffectivePermissions;
use App\Support\PermissionCatalog;
use App\Support\StaffRoles;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UpdateUserPermissionOverrides
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Replace one account's custom access. The map lists INHERIT, ALLOW or DENY per permission; permissions left out
     * are INHERIT. INHERIT deletes the row, and an ALLOW of a permission the Role already includes (or a DENY of one it
     * excludes) is stored as INHERIT, so no meaningless rows exist. Super Admin accounts are locked full access and
     * never receive overrides; every other account is limited to its Role's grant envelope.
     *
     * @param  array<string, string>  $overrides
     * @return bool whether the custom access changed
     */
    public function execute(User $actor, User $target, array $overrides): bool
    {
        return DB::transaction(function () use ($actor, $target, $overrides): bool {
            $actor = AccessControlActor::resolve($actor);
            [$target, $role] = $this->lockTarget($target);
            $baseline = EffectivePermissions::roleBaselines()[$role] ?? [];

            $desired = [];
            foreach ($overrides as $permission => $effect) {
                if (! PermissionCatalog::exists($permission)) {
                    throw ValidationException::withMessages(['overrides' => 'Choose permissions from the list only.']);
                }
                if (! in_array($effect, ['inherit', 'allow', 'deny'], true)) {
                    throw ValidationException::withMessages(['overrides.'.$permission => 'Choose Inherit, Allow or Deny.']);
                }
                if ($effect === 'inherit') {
                    continue;
                }
                if (! PermissionCatalog::isGrantable($role, $permission)) {
                    throw ValidationException::withMessages([
                        'overrides.'.$permission => PermissionCatalog::label($permission).': '.PermissionCatalog::lockReason($role, $permission),
                    ]);
                }
                $included = in_array($permission, $baseline, true);
                if (($effect === 'allow' && ! $included) || ($effect === 'deny' && $included)) {
                    $desired[$permission] = $effect;
                }
            }

            $before = $this->current($target);
            ksort($desired);
            if ($before === $desired) {
                return false;
            }

            $permissionIds = Permission::query()->whereIn('name', array_keys($desired + $before))->pluck('id', 'name');
            UserPermissionOverride::query()->where('user_id', $target->id)->delete();
            foreach ($desired as $permission => $effect) {
                UserPermissionOverride::query()->create([
                    'user_id' => $target->id,
                    'permission_id' => $permissionIds[$permission],
                    'effect' => PermissionOverrideEffect::from($effect),
                ]);
            }

            $this->record($actor, $target, $role, 'access.user_override_updated', $before, $desired);

            return true;
        });
    }

    /**
     * Remove every custom access of an account so it follows its Role baseline again.
     */
    public function reset(User $actor, User $target): bool
    {
        return DB::transaction(function () use ($actor, $target): bool {
            $actor = AccessControlActor::resolve($actor);
            [$target, $role] = $this->lockTarget($target);
            $before = $this->current($target);
            if ($before === []) {
                return false;
            }

            UserPermissionOverride::query()->where('user_id', $target->id)->delete();
            $this->record($actor, $target, $role, 'access.user_overrides_reset', $before, []);

            return true;
        });
    }

    /**
     * Locks the target account row (serializing with Staff role changes, which lock it too) and returns its single
     * canonical Role.
     *
     * @return array{User, string}
     */
    private function lockTarget(User $target): array
    {
        $target = User::query()->whereKey($target->getKey())->lockForUpdate()->firstOrFail();
        $roles = $target->roles()->pluck('name')->all();

        if (in_array(PermissionCatalog::SUPER_ADMIN, $roles, true)) {
            throw ValidationException::withMessages(['user' => 'Super Admin accounts are locked to full access and have no custom access.']);
        }
        if (count($roles) !== 1 || ! in_array($roles[0], StaffRoles::names(), true)) {
            throw ValidationException::withMessages(['user' => 'Custom access needs an account with exactly one staff role.']);
        }

        return [$target, $roles[0]];
    }

    /** @return array<string, string> */
    private function current(User $target): array
    {
        $current = array_map(fn (PermissionOverrideEffect $effect): string => $effect->value, EffectivePermissions::overrides((int) $target->id));
        ksort($current);

        return $current;
    }

    /**
     * @param  array<string, string>  $before
     * @param  array<string, string>  $after
     */
    private function record(User $actor, User $target, string $role, string $action, array $before, array $after): void
    {
        $this->audit->record(
            branch: null,
            actor: $actor,
            module: 'access_control',
            action: $action,
            auditableType: User::class,
            auditableId: (string) $target->id,
            before: ['user_id' => $target->id, 'custom_access' => $before],
            after: ['user_id' => $target->id, 'custom_access' => $after],
            metadata: [
                'employee_id' => $target->employee_id,
                'name' => $target->name,
                'role' => $role,
            ],
        );

        $summary = $after === []
            ? 'now follows the '.StaffRoles::label($role).' role only.'
            : 'custom access: '.implode(', ', array_map(
                fn (string $permission, string $effect): string => PermissionCatalog::label($permission).' '.($effect === 'allow' ? 'allowed' : 'removed'),
                array_keys($after),
                $after,
            )).'.';
        AdminNotifier::superAdmins(new AdminAlert(
            'access',
            'Custom access changed for '.$target->name,
            $actor->name.' updated '.$target->name.' ('.StaffRoles::label($role).'): '.$summary,
            route('super-admin.access-control', ['user' => $target->id], false),
        ), except: $actor);
    }
}
