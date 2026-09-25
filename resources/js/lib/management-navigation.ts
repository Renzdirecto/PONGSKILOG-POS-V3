export type ManagementSectionId =
    | 'overview'
    | 'store-operations'
    | 'sales'
    | 'catalog'
    | 'operations'
    | 'administration';

export type ManagementDestinationId =
    | 'dashboard'
    | 'pos'
    | 'qr-orders'
    | 'kitchen'
    | 'customer-display'
    | 'transactions'
    | 'reports'
    | 'products'
    | 'inventory'
    | 'plans'
    | 'overview'
    | 'ingredients'
    | 'recipes'
    | 'stock'
    | 'pamamalengke'
    | 'purchases'
    | 'staff'
    | 'settings';

/**
 * One Owner / Custom Role management destination. `permission` is the backend permission that authorizes the page, so
 * the sidebar lists exactly what the account can open. Store Operations run at one concrete Branch (`requiresBranch`).
 * The same registry serves Branch and business-wide Custom Roles: the scope changes the data and actions on each page
 * (a Branch role only ever works on its selected assigned Branch), never which pages exist.
 */
export type ManagementDestination = {
    id: ManagementDestinationId;
    label: string;
    shortLabel: string;
    section: ManagementSectionId;
    permission: string;
    requiresBranch?: boolean;
    requiresBusinessWide?: boolean;
};

export type ManagementSection = {
    id: ManagementSectionId;
    label: string;
};

export type ManagementPageState = {
    component: string;
    surface?: string;
};

export const managementSections: readonly ManagementSection[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'store-operations', label: 'Store Operations' },
    { id: 'sales', label: 'Sales' },
    { id: 'catalog', label: 'Catalog' },
    { id: 'operations', label: 'Operations' },
    { id: 'administration', label: 'Administration' },
];

export const managementDestinations: readonly ManagementDestination[] = [
    {
        id: 'dashboard',
        label: 'Dashboard',
        shortLabel: 'Home',
        section: 'overview',
        permission: 'reports.view',
    },
    {
        id: 'pos',
        label: 'POS',
        shortLabel: 'POS',
        section: 'store-operations',
        permission: 'pos.access',
        requiresBranch: true,
    },
    /** QR Orders has no permission of its own: it follows POS in every Role baseline. */
    {
        id: 'qr-orders',
        label: 'QR Orders',
        shortLabel: 'QR',
        section: 'store-operations',
        permission: 'pos.access',
        requiresBranch: true,
    },
    {
        id: 'kitchen',
        label: 'Kitchen',
        shortLabel: 'Kitchen',
        section: 'store-operations',
        permission: 'kitchen.access',
        requiresBranch: true,
    },
    {
        id: 'customer-display',
        label: 'Display',
        shortLabel: 'Display',
        section: 'store-operations',
        permission: 'customer_display.launch',
        requiresBranch: true,
    },
    {
        id: 'transactions',
        label: 'Transactions',
        shortLabel: 'Sales',
        section: 'sales',
        permission: 'transactions.view',
    },
    {
        id: 'reports',
        label: 'Reports',
        shortLabel: 'Reports',
        section: 'sales',
        permission: 'reports.view',
    },
    {
        id: 'products',
        label: 'Products',
        shortLabel: 'Products',
        section: 'catalog',
        permission: 'products.manage',
    },
    {
        id: 'inventory',
        label: 'Inventory',
        shortLabel: 'Stock',
        section: 'catalog',
        permission: 'inventory.manage',
    },
    ...(
        [
            ['plans', 'Pamalengke Plans', 'Plans'],
            ['overview', 'Overview', 'Overview'],
            ['ingredients', 'Ingredients', 'Ingredients'],
            ['recipes', 'Recipes', 'Recipes'],
            ['stock', 'Ingredient Stock', 'Stock'],
            ['pamamalengke', 'Pamamalengke', 'Market'],
            ['purchases', 'Purchases', 'Purchases'],
        ] as const
    ).map(([id, label, shortLabel]): ManagementDestination => ({
        id,
        label,
        shortLabel,
        section: 'operations',
        permission: 'operations.manage',
    })),
    {
        id: 'staff',
        label: 'Staff',
        shortLabel: 'Staff',
        section: 'administration',
        permission: 'staff.manage',
    },
    {
        id: 'settings',
        label: 'Settings',
        shortLabel: 'Settings',
        section: 'administration',
        permission: 'settings.manage',
    },
];

/**
 * Permissions whose pages exist only in the management shell. A Branch-scoped account holding one of them works in
 * the management shell (its Dashboard and Reports too) instead of the operational POS shell.
 */
export const MANAGEMENT_ONLY_PERMISSIONS = [
    'products.manage',
    'inventory.manage',
    'operations.manage',
    'staff.manage',
    'settings.manage',
] as const;

export function hasManagementPages(permissions: readonly string[]): boolean {
    return MANAGEMENT_ONLY_PERMISSIONS.some((permission) =>
        permissions.includes(permission),
    );
}

/** The first management page (outside Store Operations) the account can open, for a "back to management" link. */
export function managementLandingDestination(
    permissions: readonly string[],
    { businessWide }: { businessWide: boolean },
): ManagementDestination | null {
    return (
        managementNavigation(permissions, { businessWide })
            .filter((group) => group.section.id !== 'store-operations')
            .flatMap((group) => group.destinations)[0] ?? null
    );
}

/** Destinations pinned to the mobile dock, in preference order; the rest live in the More menu. */
const preferredPinned: readonly ManagementDestinationId[] = [
    'dashboard',
    'products',
    'inventory',
];

/**
 * Groups the registry by section, keeping only destinations the account can open. A page without permission is never
 * rendered (no disabled "No access" rows) and an empty section disappears; the backend still authorizes every page.
 */
export function managementNavigation(
    permissions: readonly string[],
    { businessWide }: { businessWide: boolean },
): { section: ManagementSection; destinations: ManagementDestination[] }[] {
    return managementSections
        .map((section) => ({
            section,
            destinations: managementDestinations.filter(
                (destination) =>
                    destination.section === section.id &&
                    permissions.includes(destination.permission) &&
                    (!destination.requiresBusinessWide || businessWide),
            ),
        }))
        .filter((group) => group.destinations.length > 0);
}

/** Up to three dock destinations: the preferred ones the account can open, then the next visible pages. */
export function pinnedManagementDestinations(
    visible: readonly ManagementDestination[],
    limit = 3,
): ManagementDestination[] {
    const preferred = preferredPinned
        .map((id) => visible.find((destination) => destination.id === id))
        .filter((destination) => destination !== undefined);
    const rest = visible.filter(
        (destination) => !preferred.includes(destination),
    );

    return [...preferred, ...rest].slice(0, limit);
}

/** The management destination rendered by the current page, if any (Store Operations render in the POS shell). */
export function activeManagementDestination(
    page: ManagementPageState,
): ManagementDestinationId | null {
    const { component } = page;

    if (component === 'workspaces/owner-dashboard') {
        return 'dashboard';
    }
    if (
        component === 'workspaces/transaction-history' &&
        page.surface === 'business'
    ) {
        return 'transactions';
    }
    if (component === 'workspaces/reports') {
        return 'reports';
    }
    if (component.startsWith('catalog/')) {
        return 'products';
    }
    if (component.startsWith('inventory/')) {
        return 'inventory';
    }
    if (component.startsWith('operations/')) {
        const destination = component.slice('operations/'.length);

        return managementDestinations.some(
            (item) => item.section === 'operations' && item.id === destination,
        )
            ? (destination as ManagementDestinationId)
            : null;
    }
    if (component === 'super-admin/staff') {
        return 'staff';
    }
    if (component === 'branches/index') {
        return 'settings';
    }

    return null;
}

/** localStorage key of the desktop sidebar preference (a per-device UI convenience, never stored on the server). */
export const MANAGEMENT_SIDEBAR_STORAGE_KEY = 'management-sidebar';

export function restoredSidebarCollapsed(storedValue: string | null): boolean {
    return storedValue === 'collapsed';
}

export function storedSidebarValue(collapsed: boolean): string {
    return collapsed ? 'collapsed' : 'expanded';
}

/**
 * The identity line under a person's name: their Staff Position (a business/job title), else their Role label. Display
 * only; access never comes from the Position.
 */
export function identitySubtitle(
    position: string | null | undefined,
    roleLabel: string | null | undefined,
    fallback = 'Staff',
): string {
    const title = position?.trim();

    return title ? title : roleLabel?.trim() || fallback;
}
