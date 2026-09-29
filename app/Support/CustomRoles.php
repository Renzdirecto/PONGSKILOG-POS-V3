<?php

namespace App\Support;

use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Shared rules of the Custom Role Builder (Super Admin only): display names, scope and the permission baseline.
 *
 * A Custom Role is a reusable permission package. Its display name is trimmed, single-spaced and unique among active
 * roles ignoring case (System role names included); its scope is Branch or business-wide; its baseline must stay inside
 * that scope's grant envelope (Control permissions never, QR Orders always follows POS).
 */
class CustomRoles
{
    public const LABEL_MAX = 40;

    /** Letters, numbers, spaces and a few separators; starts with a letter or number. */
    public const LABEL_PATTERN = "/\\A[\\pL\\pN][\\pL\\pN &+\\-\\/().',]*\\z/u";

    public static function normalizeLabel(mixed $label): mixed
    {
        return is_string($label) ? trim((string) preg_replace('/\s+/u', ' ', $label)) : $label;
    }

    /**
     * Whether another active role already uses this display name, ignoring case.
     */
    public static function labelTaken(string $label, ?int $exceptRoleId = null): bool
    {
        return Role::query()
            ->whereNull('archived_at')
            ->whereRaw('LOWER(label) = ?', [mb_strtolower($label)])
            ->when($exceptRoleId !== null, fn ($query) => $query->whereKeyNot($exceptRoleId))
            ->exists()
            || in_array(mb_strtolower($label), array_map(mb_strtolower(...), StaffRoles::LABELS), true);
    }

    /**
     * @throws ValidationException
     */
    public static function validateLabel(string $label, ?int $exceptRoleId = null): void
    {
        if ($label === '' || mb_strlen($label) > self::LABEL_MAX || preg_match(self::LABEL_PATTERN, $label) !== 1) {
            throw ValidationException::withMessages(['label' => 'Use 1–'.self::LABEL_MAX.' letters, numbers, spaces or & + - / ( ) . \' ,']);
        }
        if (self::labelTaken($label, $exceptRoleId)) {
            throw ValidationException::withMessages(['label' => 'A role named "'.$label.'" already exists. Choose a different name.']);
        }
    }

    /**
     * The complete baseline for a Custom Role with this scope, in catalog order with QR Orders following POS.
     *
     * @param  array<int, mixed>  $permissions
     * @return list<string>
     *
     * @throws ValidationException
     */
    public static function baseline(string $scope, array $permissions): array
    {
        if (! array_key_exists($scope, PermissionCatalog::CUSTOM_GRANTABLE)) {
            throw ValidationException::withMessages(['scope' => 'Choose Branch or Business-wide scope.']);
        }
        $submitted = array_values(array_unique(array_map('strval', $permissions)));
        foreach ($submitted as $permission) {
            $reason = PermissionCatalog::scopeLockReason($scope, $permission);
            if ($reason !== null) {
                throw ValidationException::withMessages([
                    'permissions' => PermissionCatalog::exists($permission)
                        ? PermissionCatalog::label($permission).': '.$reason
                        : 'Choose permissions from the list only.',
                ]);
            }
        }

        return PermissionCatalog::withQrFollowingPos($submitted);
    }

    /**
     * Number of Staff accounts holding the role (read inside the caller's transaction).
     */
    public static function assignedCount(Role $role): int
    {
        return DB::table('user_roles')->where('role_id', $role->id)->count();
    }

    /**
     * An audit-safe snapshot of a Custom Role: identity, name, scope and permission names with their labels.
     *
     * @param  list<string>  $permissions
     * @return array{role_id: int, key: string, label: string, scope: string|null, scope_label: string|null, permissions: list<string>, permission_labels: list<string>}
     */
    public static function snapshot(Role $role, array $permissions): array
    {
        return [
            'role_id' => $role->id,
            'key' => $role->name,
            'label' => $role->displayLabel(),
            'scope' => $role->scope,
            'scope_label' => $role->scope === null ? null : PermissionCatalog::SCOPES[$role->scope],
            'permissions' => $permissions,
            'permission_labels' => array_map(PermissionCatalog::label(...), $permissions),
        ];
    }

    /**
     * @return list<string>
     */
    public static function permissionsOf(Role $role): array
    {
        return PermissionCatalog::ordered($role->permissions()->pluck('permissions.name')->all());
    }
}
