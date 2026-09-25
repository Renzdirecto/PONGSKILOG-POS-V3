<?php

namespace App\Support;

use App\Models\Role;

/**
 * The one catalog of seeded permissions: user-facing metadata, first-install Role defaults and the safe grant envelope
 * of every Role. Access Control, the RBAC seeder, the effective-permission resolver and the React matrix all read it,
 * so no second list of permission names or labels exists anywhere else.
 *
 * Permission = WHAT an account may do. Role + Branch assignment = WHERE. A permission is grantable to a Role only when
 * the existing backend keeps it inside that Role's scope; everything else is locked with a readable reason.
 */
class PermissionCatalog
{
    public const SUPER_ADMIN = 'super_admin';

    public const DERIVED_ROLE = 'cashier_kitchen';

    /** Cashier + Kitchen is always the union of these two Role baselines. */
    public const DERIVED_FROM = ['cashier', 'kitchen_staff'];

    /** Roles whose baseline the Super Admin edits directly, in display order. */
    public const EDITABLE_ROLES = ['owner', 'cashier', 'kitchen_staff'];

    /** Display order of every canonical Role in Access Control. */
    public const ROLES = ['owner', 'cashier', 'kitchen_staff', 'cashier_kitchen', 'super_admin'];

    /** Control permissions that never leave the Super Admin, by baseline or by user override. */
    public const SUPER_ADMIN_ONLY = ['audit.view', 'void_orders.manage', 'access_control.manage'];

    /** QR Orders has no route of its own; it is enforced through POS access and follows it in every Role baseline. */
    public const FOLLOWS_POS = 'qr_orders.access';

    /** @var array<string, string> */
    public const CATEGORIES = [
        'operations' => 'Operations',
        'management' => 'Management',
        'control' => 'Control',
    ];

    /**
     * scope: 'branch' works only inside assigned Branches, 'business' is inherently business-wide, 'either' follows
     * the account's own scope (a Branch-scoped account sees only its assigned Branches).
     *
     * @var array<string, array{label: string, description: string, category: 'operations'|'management'|'control', scope: 'branch'|'business'|'either'}>
     */
    public const PERMISSIONS = [
        'pos.access' => [
            'label' => 'POS',
            'description' => 'Take orders, Pay Now and Pay Later, and open the Cashier Dashboard at an assigned Branch.',
            'category' => 'operations',
            'scope' => 'branch',
        ],
        'qr_orders.access' => [
            'label' => 'QR Orders',
            'description' => 'Load and manage Customer QR orders. Always follows POS access.',
            'category' => 'operations',
            'scope' => 'branch',
        ],
        'transactions.view' => [
            'label' => 'Transactions',
            'description' => 'Transaction History for the assigned Branch. Owner reads business-wide Transactions.',
            'category' => 'operations',
            'scope' => 'either',
        ],
        'store.open_close' => [
            'label' => 'Store Open / Close',
            'description' => 'Open the Store Session and close it with cash reconciliation.',
            'category' => 'operations',
            'scope' => 'branch',
        ],
        'store_expenses.manage' => [
            'label' => 'Expenses',
            'description' => 'Store purchases and expenses, Store inventory adjustments and giveaways in the open Store Session.',
            'category' => 'operations',
            'scope' => 'branch',
        ],
        'kitchen.access' => [
            'label' => 'Kitchen',
            'description' => 'Open the Kitchen display and move orders through preparation.',
            'category' => 'operations',
            'scope' => 'branch',
        ],
        'customer_display.launch' => [
            'label' => 'Customer Display',
            'description' => 'Launch the order-number Customer Display for the Branch.',
            'category' => 'operations',
            'scope' => 'branch',
        ],
        'reports.view' => [
            'label' => 'Reports',
            'description' => 'Read-only sales reports. Branch staff see only their assigned Branch; Owner and Super Admin see every Branch.',
            'category' => 'management',
            'scope' => 'either',
        ],
        'products.manage' => [
            'label' => 'Products',
            'description' => 'Edit the shared product catalog, categories, modifiers and Branch prices.',
            'category' => 'management',
            'scope' => 'business',
        ],
        'inventory.manage' => [
            'label' => 'Inventory',
            'description' => 'Business stock management and Owner Operations (Ingredients, Recipes, Pamamalengke).',
            'category' => 'management',
            'scope' => 'business',
        ],
        'staff.manage' => [
            'label' => 'Staff',
            'description' => 'Create and manage operational Staff accounts (Cashier, Kitchen Staff, Cashier + Kitchen).',
            'category' => 'management',
            'scope' => 'business',
        ],
        'settings.manage' => [
            'label' => 'Settings',
            'description' => 'Business settings: Branches, Customer QR and receipt settings.',
            'category' => 'management',
            'scope' => 'business',
        ],
        'audit.view' => [
            'label' => 'Audit Trail',
            'description' => 'Read the business-wide audit record.',
            'category' => 'control',
            'scope' => 'business',
        ],
        'void_orders.manage' => [
            'label' => 'Void Orders',
            'description' => 'Void records and the global Void approval PIN.',
            'category' => 'control',
            'scope' => 'business',
        ],
        'access_control.manage' => [
            'label' => 'Access Control',
            'description' => 'Control Center: Staff administration, Role baselines, custom access and notifications.',
            'category' => 'control',
            'scope' => 'business',
        ],
    ];

    /**
     * First-install defaults. The seeder applies them only when a Role or a Permission is created, never over a live
     * Access Control configuration (Super Admin is always re-completed and Cashier + Kitchen always re-derived).
     *
     * @var array<string, list<string>>
     */
    public const ROLE_DEFAULTS = [
        'owner' => [
            'transactions.view',
            'reports.view',
            'products.manage',
            'inventory.manage',
            'staff.manage',
            'settings.manage',
        ],
        'cashier' => [
            'pos.access',
            'qr_orders.access',
            'transactions.view',
            'store.open_close',
            'store_expenses.manage',
        ],
        'kitchen_staff' => [
            'kitchen.access',
            'customer_display.launch',
        ],
    ];

    /**
     * The permissions each directly configured Role may hold, as baseline or as a user's custom access. Anything else
     * is locked for that Role because the backend could not keep it inside the Role's scope.
     *
     * @var array<string, list<string>>
     */
    public const GRANTABLE = [
        'owner' => ['transactions.view', 'reports.view', 'products.manage', 'inventory.manage', 'staff.manage', 'settings.manage'],
        'cashier' => ['pos.access', 'transactions.view', 'store.open_close', 'store_expenses.manage', 'kitchen.access', 'customer_display.launch', 'reports.view'],
        'kitchen_staff' => ['kitchen.access', 'customer_display.launch', 'reports.view'],
    ];

    /** @var array<'branch'|'business', string> */
    public const SCOPES = [
        'branch' => 'Branch',
        'business' => 'Business-wide',
    ];

    /**
     * The grant envelope of a Custom Role, by its scope. A Branch Custom Role runs the same Branch-scoped surfaces as
     * Cashier staff (the backend keeps each inside the assigned Branches, Reports included); a business-wide Custom Role
     * gets the Owner management surfaces, whose backend already requires business-wide scope. Control stays with the
     * Super Admin and QR Orders follows POS, so neither is ever listed.
     *
     * @var array<'branch'|'business', list<string>>
     */
    public const CUSTOM_GRANTABLE = [
        'branch' => ['pos.access', 'transactions.view', 'store.open_close', 'store_expenses.manage', 'kitchen.access', 'customer_display.launch', 'reports.view'],
        'business' => ['transactions.view', 'reports.view', 'products.manage', 'inventory.manage', 'staff.manage', 'settings.manage'],
    ];

    /** @return list<string> */
    public static function names(): array
    {
        return array_keys(self::PERMISSIONS);
    }

    public static function exists(string $permission): bool
    {
        return array_key_exists($permission, self::PERMISSIONS);
    }

    /**
     * Seeded defaults for every Role, including the locked Super Admin (everything) and the derived Cashier + Kitchen.
     *
     * @return list<string>
     */
    public static function defaultsFor(string $role): array
    {
        return match ($role) {
            self::SUPER_ADMIN => self::names(),
            self::DERIVED_ROLE => self::union(array_map(fn (string $source): array => self::ROLE_DEFAULTS[$source], self::DERIVED_FROM)),
            default => self::ROLE_DEFAULTS[$role] ?? [],
        };
    }

    /**
     * Why a permission cannot be configured for a Role (baseline or user override), or null when it can. System roles
     * are identified by name; a Custom Role must be passed as its model so its scope decides the envelope.
     */
    public static function lockReason(Role|string $role, string $permission): ?string
    {
        if ($role instanceof Role && $role->isCustom()) {
            return $role->scope === null
                ? 'This custom role has no access scope.'
                : self::scopeLockReason($role->scope, $permission);
        }
        $role = $role instanceof Role ? $role->name : $role;

        if (! self::exists($permission)) {
            return 'Unknown permission.';
        }
        if ($role === self::SUPER_ADMIN) {
            return 'Super Admin is locked to full access.';
        }
        if (in_array($permission, self::SUPER_ADMIN_ONLY, true)) {
            return 'Super Admin only.';
        }
        if ($permission === self::FOLLOWS_POS) {
            return 'QR Orders always follow POS access.';
        }
        if (! StaffRoles::isSystem($role)) {
            return 'Not available for this role.';
        }
        if ($role === self::DERIVED_ROLE) {
            $reasons = array_map(fn (string $source): ?string => self::lockReason($source, $permission), self::DERIVED_FROM);

            return in_array(null, $reasons, true) ? null : $reasons[0];
        }
        if (in_array($permission, self::GRANTABLE[$role] ?? [], true)) {
            return null;
        }

        $scope = self::PERMISSIONS[$permission]['scope'];
        $label = self::PERMISSIONS[$permission]['label'];

        return match (true) {
            $role === 'owner' => 'Owner is a business-wide management role; POS, Kitchen and Store operations belong to Branch staff.',
            $scope === 'business' => $label.' is business-wide and cannot be limited to one Branch, so it stays with Owner and Super Admin.',
            $role === 'kitchen_staff' => $label.' requires the Cashier or Cashier + Kitchen role.',
            default => 'Not available for this role.',
        };
    }

    /**
     * Why a permission is outside the grant envelope of a Custom Role with this scope, or null when it is inside.
     */
    public static function scopeLockReason(string $scope, string $permission): ?string
    {
        if (! self::exists($permission)) {
            return 'Unknown permission.';
        }
        if (! array_key_exists($scope, self::CUSTOM_GRANTABLE)) {
            return 'Choose Branch or Business-wide scope.';
        }
        if (in_array($permission, self::SUPER_ADMIN_ONLY, true)) {
            return 'Super Admin only.';
        }
        if ($permission === self::FOLLOWS_POS) {
            return 'QR Orders always follow POS access.';
        }
        if (in_array($permission, self::CUSTOM_GRANTABLE[$scope], true)) {
            return null;
        }
        $label = self::PERMISSIONS[$permission]['label'];

        return $scope === 'branch'
            ? $label.' is business-wide and cannot be limited to one Branch. Use a Business-wide role for it.'
            : $label.' runs inside one Branch. Use a Branch role for it.';
    }

    /**
     * Lock reasons of every permission for a Custom Role scope, for the Custom Role builder.
     *
     * @return array<string, string|null>
     */
    public static function scopeLocks(string $scope): array
    {
        return array_combine(self::names(), array_map(fn (string $permission): ?string => self::scopeLockReason($scope, $permission), self::names()));
    }

    public static function isGrantable(Role|string $role, string $permission): bool
    {
        return self::lockReason($role, $permission) === null;
    }

    /** @return list<string> */
    public static function grantableFor(Role|string $role): array
    {
        return array_values(array_filter(self::names(), fn (string $permission): bool => self::isGrantable($role, $permission)));
    }

    /**
     * Keeps QR Orders aligned with POS in a Role baseline.
     *
     * @param  list<string>  $permissions
     * @return list<string>
     */
    public static function withQrFollowingPos(array $permissions): array
    {
        $permissions = array_values(array_diff($permissions, [self::FOLLOWS_POS]));

        return in_array('pos.access', $permissions, true) ? self::ordered([...$permissions, self::FOLLOWS_POS]) : self::ordered($permissions);
    }

    /**
     * @param  list<list<string>>  $sets
     * @return list<string>
     */
    public static function union(array $sets): array
    {
        return self::ordered(array_merge(...$sets));
    }

    /**
     * Catalog order, unique, known names only.
     *
     * @param  array<int, string>  $permissions
     * @return list<string>
     */
    public static function ordered(array $permissions): array
    {
        return array_values(array_filter(self::names(), fn (string $permission): bool => in_array($permission, $permissions, true)));
    }

    /**
     * Metadata for the Access Control page (no second copy in React).
     *
     * @return list<array{key: string, label: string, description: string, category: string, scope: string, super_admin_only: bool}>
     */
    public static function present(): array
    {
        return array_map(fn (string $permission): array => [
            'key' => $permission,
            'label' => self::PERMISSIONS[$permission]['label'],
            'description' => self::PERMISSIONS[$permission]['description'],
            'category' => self::PERMISSIONS[$permission]['category'],
            'scope' => self::PERMISSIONS[$permission]['scope'],
            'super_admin_only' => in_array($permission, self::SUPER_ADMIN_ONLY, true),
        ], self::names());
    }

    public static function label(string $permission): string
    {
        return self::PERMISSIONS[$permission]['label'] ?? $permission;
    }
}
