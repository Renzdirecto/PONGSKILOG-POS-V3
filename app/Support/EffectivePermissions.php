<?php

namespace App\Support;

use App\Enums\PermissionOverrideEffect;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The single authority for what an account may do. Every backend check (`User::hasPermission()`, permission
 * middleware, form requests, channels) and the shared Inertia permission list resolve here, so navigation and
 * authorization always agree.
 *
 *   Super Admin               = its Role baseline (seeded complete); user overrides are ignored.
 *   everyone else, permission = ALLOW override → yes; DENY override → no; no override (INHERIT) → Role baseline.
 *
 * An ALLOW override never grants a Super-Admin-only Control permission, even if such a row were written directly.
 * Nothing is cached across requests, so a revoked permission is denied on the very next request.
 */
class EffectivePermissions
{
    public static function has(User $user, string $permission): bool
    {
        $userId = (int) $user->getKey();
        $allowCanApply = ! in_array($permission, PermissionCatalog::SUPER_ADMIN_ONLY, true);

        return DB::table('permissions')
            ->where('permissions.name', $permission)
            ->where(function (Builder $query) use ($userId, $allowCanApply): void {
                $query->where(function (Builder $baseline) use ($userId): void {
                    $baseline->whereExists(fn (Builder $role) => self::roleGrants($role, $userId))
                        ->where(function (Builder $notDenied) use ($userId): void {
                            $notDenied->whereNotExists(fn (Builder $override) => self::override($override, $userId, PermissionOverrideEffect::Deny))
                                ->orWhereExists(fn (Builder $role) => self::isSuperAdmin($role, $userId));
                        });
                });

                if ($allowCanApply) {
                    $query->orWhere(function (Builder $allowed) use ($userId): void {
                        $allowed->whereExists(fn (Builder $override) => self::override($override, $userId, PermissionOverrideEffect::Allow))
                            ->whereNotExists(fn (Builder $role) => self::isSuperAdmin($role, $userId));
                    });
                }
            })
            ->exists();
    }

    /**
     * Every effective permission name of one account, alphabetically, using two queries.
     *
     * @return list<string>
     */
    public static function names(User $user): array
    {
        $userId = (int) $user->getKey();
        $rows = DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->leftJoin('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->leftJoin('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('user_roles.user_id', $userId)
            ->get(['roles.name as role', 'permissions.name as permission']);
        $baseline = $rows->pluck('permission')->filter()->unique()->values()->all();

        if ($rows->contains('role', PermissionCatalog::SUPER_ADMIN)) {
            return self::sorted($baseline);
        }

        $overrides = self::overrides($userId);
        $allowed = array_keys(array_filter($overrides, fn (PermissionOverrideEffect $effect): bool => $effect === PermissionOverrideEffect::Allow));
        $denied = array_keys(array_filter($overrides, fn (PermissionOverrideEffect $effect): bool => $effect === PermissionOverrideEffect::Deny));
        $allowed = array_diff($allowed, PermissionCatalog::SUPER_ADMIN_ONLY);

        return self::sorted(array_merge(array_diff($baseline, $denied), $allowed));
    }

    /**
     * An account's explicit overrides keyed by permission name.
     *
     * @return array<string, PermissionOverrideEffect>
     */
    public static function overrides(int $userId): array
    {
        return DB::table('user_permission_overrides')
            ->join('permissions', 'permissions.id', '=', 'user_permission_overrides.permission_id')
            ->where('user_permission_overrides.user_id', $userId)
            ->pluck('user_permission_overrides.effect', 'permissions.name')
            ->map(fn (string $effect): PermissionOverrideEffect => PermissionOverrideEffect::from($effect))
            ->all();
    }

    /**
     * Current Role baselines keyed by Role name, in catalog order (one query).
     *
     * @return array<string, list<string>>
     */
    public static function roleBaselines(): array
    {
        return DB::table('roles')
            ->leftJoin('role_permissions', 'role_permissions.role_id', '=', 'roles.id')
            ->leftJoin('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->get(['roles.name as role', 'permissions.name as permission'])
            ->groupBy('role')
            ->map(fn ($rows): array => PermissionCatalog::ordered($rows->pluck('permission')->filter()->all()))
            ->all();
    }

    private static function roleGrants(Builder $query, int $userId): void
    {
        $query->selectRaw('1')
            ->from('user_roles')
            ->join('role_permissions', 'role_permissions.role_id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->whereColumn('role_permissions.permission_id', 'permissions.id');
    }

    private static function override(Builder $query, int $userId, PermissionOverrideEffect $effect): void
    {
        $query->selectRaw('1')
            ->from('user_permission_overrides')
            ->where('user_permission_overrides.user_id', $userId)
            ->whereColumn('user_permission_overrides.permission_id', 'permissions.id')
            ->where('user_permission_overrides.effect', $effect->value);
    }

    private static function isSuperAdmin(Builder $query, int $userId): void
    {
        $query->selectRaw('1')
            ->from('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('user_roles.user_id', $userId)
            ->where('roles.name', PermissionCatalog::SUPER_ADMIN);
    }

    /**
     * Unique names in alphabetical order (the shared `auth.permissions` contract).
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    private static function sorted(array $permissions): array
    {
        $permissions = array_values(array_unique($permissions));
        sort($permissions);

        return $permissions;
    }
}
