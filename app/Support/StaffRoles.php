<?php

namespace App\Support;

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

    /** @return list<array{name: string, label: string, business_wide: bool}> */
    public static function options(): array
    {
        return array_map(fn (string $role): array => [
            'name' => $role,
            'label' => self::label($role),
            'business_wide' => self::isBusinessWide($role),
        ], self::names());
    }
}
