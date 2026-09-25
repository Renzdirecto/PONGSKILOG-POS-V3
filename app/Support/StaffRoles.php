<?php

namespace App\Support;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The roles a Staff login account may hold: the five canonical System Roles plus the active Custom Roles a Super Admin
 * created in Access Control. System roles are identified by their unchanged machine names; Custom Roles by their stable
 * `custom_{id}` key, with an editable display name and an explicit Branch or business-wide scope.
 */
class StaffRoles
{
    /** @var array<string, string> */
    public const LABELS = [
        'cashier' => 'Cashier',
        'kitchen_staff' => 'Kitchen Staff',
        'cashier_kitchen' => 'Cashier + Kitchen',
        'owner' => 'Owner',
        'super_admin' => 'Super Admin',
    ];

    /** @var array<string, 'branch'|'business'> */
    public const SYSTEM_SCOPES = [
        'cashier' => 'branch',
        'kitchen_staff' => 'branch',
        'cashier_kitchen' => 'branch',
        'owner' => 'business',
        'super_admin' => 'business',
    ];

    /** Business-wide system roles never take Branch assignments; they reach every Branch through role scope. */
    public const BUSINESS_WIDE = ['owner', 'super_admin'];

    /** Operational Staff an Owner may manage; Owner, Super Admin and Custom Role accounts stay with Super Admin access control. */
    public const OPERATIONAL = ['cashier', 'kitchen_staff', 'cashier_kitchen'];

    /** System roles that run Cashier operations (Branch Custom Roles also do; see Role::scopeCashierOperations()). */
    public const CASHIER_OPERATIONS = ['cashier', 'cashier_kitchen', 'super_admin'];

    /**
     * The roles an actor may view and assign on Staff accounts. Super Admin access control (`access_control.manage`)
     * covers every System role and every active Custom Role; business-wide Staff management (`staff.manage`, the Owner
     * or a business-wide Custom Role) covers the operational System roles only.
     *
     * @return list<string>
     */
    public static function manageableBy(User $actor): array
    {
        if (! $actor->is_active) {
            return [];
        }
        if ($actor->hasPermission('access_control.manage')) {
            return [...self::names(), ...self::assignableCustomNames()];
        }
        if ($actor->hasPermission('staff.manage') && $actor->hasBusinessWideScope()) {
            return self::OPERATIONAL;
        }

        return [];
    }

    /** Whether the actor administers every Staff account (Super Admin access control), not only operational Staff. */
    public static function managesEveryAccount(User $actor): bool
    {
        return $actor->is_active && $actor->hasPermission('access_control.manage');
    }

    /**
     * Whether an actor may see and manage an existing account: every one of its roles must be manageable, so an
     * Owner never reaches an Owner, Super Admin or Custom Role account, nor a login without any role.
     */
    public static function canManage(User $actor, User $staff): bool
    {
        $allowed = self::manageableBy($actor);
        $roles = $staff->roles()->pluck('name')->all();

        return $allowed !== [] && $roles !== [] && array_diff($roles, $allowed) === [];
    }

    /**
     * The ids of every account holding the Super Admin role, as a subquery-ready builder.
     */
    public static function superAdminUserIds(): Builder
    {
        return DB::table('user_roles')
            ->join('roles', 'roles.id', '=', 'user_roles.role_id')
            ->where('roles.name', 'super_admin')
            ->select('user_roles.user_id');
    }

    /**
     * The System role machine names, in canonical order.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isSystem(string $role): bool
    {
        return array_key_exists($role, self::LABELS);
    }

    /** @return list<string> */
    public static function assignableCustomNames(): array
    {
        return array_values(Role::query()->assignableCustom()->pluck('name')->all());
    }

    /**
     * Whether a role (by machine name) is business-wide: canonical for System roles, stored scope for a Custom Role.
     */
    public static function isBusinessWide(string $role): bool
    {
        if (self::isSystem($role)) {
            return in_array($role, self::BUSINESS_WIDE, true);
        }

        return Role::query()->where('name', $role)->first()?->isBusinessWide() ?? false;
    }

    /**
     * Why a business-wide role takes no Branch assignment, worded for the System or Custom Role submitted.
     */
    public static function branchesProhibitedMessage(mixed $role): string
    {
        $name = $role instanceof Role ? $role->name : $role;

        return is_string($name) && ! self::isSystem($name)
            ? 'A business-wide custom role reaches every Branch and does not take Branch assignments.'
            : 'Owner and Super Admin accounts have business-wide access and do not take Branch assignments.';
    }

    public static function label(string $role): string
    {
        if (self::isSystem($role)) {
            return self::LABELS[$role];
        }

        return Role::query()->where('name', $role)->value('label') ?? $role;
    }

    /**
     * Assignable role options for Staff forms, System roles first (canonical order) then active Custom Roles by name.
     * One query.
     *
     * @param  list<string>|null  $roles  limit the options to these role names
     * @return list<array{name: string, label: string, business_wide: bool, custom: bool}>
     */
    public static function options(?array $roles = null): array
    {
        $models = Role::query()
            ->where(fn ($query) => $query->whereIn('name', self::names())->orWhere(fn ($custom) => $custom->assignableCustom()))
            ->get(['id', 'name', 'label', 'is_system', 'scope', 'archived_at'])
            ->filter(fn (Role $role): bool => $role->isAssignable() && ($roles === null || in_array($role->name, $roles, true)))
            ->sortBy(fn (Role $role): string => $role->isCustom()
                ? '1'.mb_strtolower($role->displayLabel()).'#'.str_pad((string) $role->id, 12, '0', STR_PAD_LEFT)
                : '0'.array_search($role->name, self::names(), true));

        return array_values($models->map(fn (Role $role): array => [
            'name' => $role->name,
            'label' => $role->displayLabel(),
            'business_wide' => $role->isBusinessWide(),
            'custom' => $role->isCustom(),
        ])->all());
    }
}
