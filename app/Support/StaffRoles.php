<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The canonical seeded roles a Super Admin may assign to a staff login account.
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

    /** Business-wide roles never take Branch assignments; they reach every Branch through role scope. */
    public const BUSINESS_WIDE = ['owner', 'super_admin'];

    /** Operational Staff an Owner may manage; Owner and Super Admin accounts stay with Super Admin access control. */
    public const OPERATIONAL = ['cashier', 'kitchen_staff', 'cashier_kitchen'];

    /**
     * The roles an actor may view and create Staff accounts for. Super Admin access control (`access_control.manage`)
     * covers every role; business-wide Staff management (`staff.manage`, the Owner) covers operational roles only.
     *
     * @return list<string>
     */
    public static function manageableBy(User $actor): array
    {
        if (! $actor->is_active) {
            return [];
        }
        if ($actor->hasPermission('access_control.manage')) {
            return self::names();
        }
        if ($actor->hasPermission('staff.manage') && $actor->hasBusinessWideScope()) {
            return self::OPERATIONAL;
        }

        return [];
    }

    /**
     * Whether an actor may see and manage an existing account: every one of its roles must be manageable, so an
     * Owner never reaches an Owner or Super Admin account, nor a login without any role.
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

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::LABELS);
    }

    public static function isBusinessWide(string $role): bool
    {
        return in_array($role, self::BUSINESS_WIDE, true);
    }

    public static function label(string $role): string
    {
        return self::LABELS[$role] ?? $role;
    }

    /**
     * @param  list<string>|null  $roles  limit the options to these roles, in canonical order
     * @return list<array{name: string, label: string, business_wide: bool}>
     */
    public static function options(?array $roles = null): array
    {
        return array_values(array_map(fn (string $role): array => [
            'name' => $role,
            'label' => self::label($role),
            'business_wide' => self::isBusinessWide($role),
        ], array_filter(self::names(), fn (string $role): bool => $roles === null || in_array($role, $roles, true))));
    }
}
