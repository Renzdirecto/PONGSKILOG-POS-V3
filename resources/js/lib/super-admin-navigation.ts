export type SuperAdminSectionId =
    | 'overview'
    | 'operations'
    | 'owner'
    | 'owner-operations'
    | 'control';

export type SuperAdminDestinationId =
    | 'dashboard'
    | 'notifications'
    | 'cashier-dashboard'
    | 'pos'
    | 'qr-orders'
    | 'transaction-history'
    | 'kitchen'
    | 'customer-display'
    | 'owner-dashboard'
    | 'owner-transactions'
    | 'reports'
    | 'products'
    | 'inventory'
    | 'ops-plans'
    | 'ops-overview'
    | 'ops-ingredients'
    | 'ops-recipes'
    | 'ops-stock'
    | 'ops-pamamalengke'
    | 'ops-purchases'
    | 'audit-trail'
    | 'void-orders'
    | 'staff'
    | 'access-control'
    | 'settings';

/**
 * One Super Admin navigation destination. `permission` is the backend permission that authorizes the
 * destination, so future role/page access can filter the same registry instead of hardcoding links.
 */
export type SuperAdminDestination = {
    id: SuperAdminDestinationId;
    label: string;
    shortLabel: string;
    section: SuperAdminSectionId;
    routeName: string;
    permission: string;
    availability: 'live' | 'planned';
    requiresBranch: boolean;
};

export type SuperAdminSection = {
    id: SuperAdminSectionId;
    label: string;
};

export type SuperAdminPageState = {
    component: string;
    url: string;
    workspace?: string;
    destination?: string;
    surface?: string;
};

export const superAdminSections: readonly SuperAdminSection[] = [
    { id: 'overview', label: 'Overview' },
    { id: 'operations', label: 'Cashier + Kitchen' },
    { id: 'owner', label: 'Owner' },
    { id: 'owner-operations', label: 'Operations' },
    { id: 'control', label: 'Control' },
];

export const superAdminDestinations: readonly SuperAdminDestination[] = [
    {
        id: 'dashboard',
        label: 'Dashboard',
        shortLabel: 'Home',
        section: 'overview',
        routeName: 'workspaces.super-admin',
        permission: 'access_control.manage',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'notifications',
        label: 'Notifications',
        shortLabel: 'Alerts',
        section: 'overview',
        routeName: 'super-admin.notifications',
        permission: 'access_control.manage',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'cashier-dashboard',
        label: 'Cashier Dashboard',
        shortLabel: 'Cashier',
        section: 'operations',
        routeName: 'workspaces.cashier-dashboard',
        permission: 'pos.access',
        availability: 'live',
        requiresBranch: true,
    },
    {
        id: 'pos',
        label: 'POS / Orders',
        shortLabel: 'POS',
        section: 'operations',
        routeName: 'workspaces.cashier',
        permission: 'pos.access',
        availability: 'live',
        requiresBranch: true,
    },
    {
        id: 'qr-orders',
        label: 'QR Orders',
        shortLabel: 'QR',
        section: 'operations',
        routeName: 'workspaces.cashier',
        permission: 'pos.access',
        availability: 'live',
        requiresBranch: true,
    },
    {
        id: 'transaction-history',
        label: 'Transaction History',
        shortLabel: 'History',
        section: 'operations',
        routeName: 'workspaces.transaction-history',
        permission: 'transactions.view',
        availability: 'live',
        requiresBranch: true,
    },
    {
        id: 'kitchen',
        label: 'Kitchen',
        shortLabel: 'Kitchen',
        section: 'operations',
        routeName: 'workspaces.kitchen',
        permission: 'kitchen.access',
        availability: 'live',
        requiresBranch: true,
    },
    {
        id: 'customer-display',
        label: 'Customer Display',
        shortLabel: 'Display',
        section: 'operations',
        routeName: 'workspaces.customer-display',
        permission: 'customer_display.launch',
        availability: 'live',
        requiresBranch: true,
    },
    {
        id: 'owner-dashboard',
        label: 'Owner Dashboard',
        shortLabel: 'Owner',
        section: 'owner',
        routeName: 'workspaces.owner',
        permission: 'reports.view',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'owner-transactions',
        label: 'Transactions',
        shortLabel: 'Sales',
        section: 'owner',
        routeName: 'workspaces.transactions',
        permission: 'transactions.view',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'reports',
        label: 'Reports',
        shortLabel: 'Reports',
        section: 'owner',
        routeName: 'workspaces.reports',
        permission: 'reports.view',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'products',
        label: 'Products',
        shortLabel: 'Products',
        section: 'owner',
        routeName: 'products.index',
        permission: 'products.manage',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'inventory',
        label: 'Inventory',
        shortLabel: 'Stock',
        section: 'owner',
        routeName: 'inventory.index',
        permission: 'inventory.manage',
        availability: 'live',
        requiresBranch: false,
    },
    ...(
        [
            ['ops-plans', 'Pamalengke Plans', 'Plans', 'operations.plans'],
            ['ops-overview', 'Overview', 'Overview', 'operations.overview'],
            [
                'ops-ingredients',
                'Ingredients',
                'Ingredients',
                'operations.ingredients',
            ],
            ['ops-recipes', 'Recipes', 'Recipes', 'operations.recipes'],
            ['ops-stock', 'Ingredient Stock', 'Stock', 'operations.stock'],
            [
                'ops-pamamalengke',
                'Pamamalengke',
                'Market',
                'operations.pamamalengke',
            ],
            ['ops-purchases', 'Purchases', 'Purchases', 'operations.purchases'],
        ] as const
    ).map(([id, label, shortLabel, routeName]): SuperAdminDestination => ({
        id,
        label,
        shortLabel,
        section: 'owner-operations',
        routeName,
        permission: 'inventory.manage',
        availability: 'live',
        requiresBranch: false,
    })),
    {
        id: 'audit-trail',
        label: 'Audit Trail',
        shortLabel: 'Audit',
        section: 'control',
        routeName: 'workspaces.audit-trail',
        permission: 'audit.view',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'void-orders',
        label: 'Void Orders',
        shortLabel: 'Voids',
        section: 'control',
        routeName: 'workspaces.void-orders',
        permission: 'void_orders.manage',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'staff',
        label: 'Staff',
        shortLabel: 'Staff',
        section: 'control',
        routeName: 'super-admin.staff.index',
        permission: 'access_control.manage',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'access-control',
        label: 'Access Control',
        shortLabel: 'Access',
        section: 'control',
        routeName: 'super-admin.access-control',
        permission: 'access_control.manage',
        availability: 'live',
        requiresBranch: false,
    },
    {
        id: 'settings',
        label: 'Settings',
        shortLabel: 'Settings',
        section: 'control',
        routeName: 'branches.index',
        permission: 'settings.manage',
        availability: 'live',
        requiresBranch: false,
    },
];

/** Destinations pinned to the compact tablet rail and mobile dock; everything else lives in the menu. */
export const superAdminPinnedDestinations: readonly SuperAdminDestinationId[] =
    ['dashboard', 'staff', 'audit-trail'];

/** Groups the registry by section, keeping only destinations the viewer's permissions authorize. */
export function superAdminNavigation(
    permissions: readonly string[],
): { section: SuperAdminSection; destinations: SuperAdminDestination[] }[] {
    return superAdminSections
        .map((section) => ({
            section,
            destinations: superAdminDestinations.filter(
                (destination) =>
                    destination.section === section.id &&
                    permissions.includes(destination.permission),
            ),
        }))
        .filter((group) => group.destinations.length > 0);
}

export function activeSuperAdminDestination(
    page: SuperAdminPageState,
): SuperAdminDestinationId | null {
    const { component } = page;

    if (component === 'super-admin/dashboard') {
        return 'dashboard';
    }
    if (component === 'super-admin/notifications') {
        return 'notifications';
    }
    if (component === 'super-admin/access-control') {
        return 'access-control';
    }
    if (component === 'workspaces/reports') {
        return 'reports';
    }
    if (component === 'super-admin/staff') {
        return 'staff';
    }
    if (component === 'super-admin/audit-trail') {
        return 'audit-trail';
    }
    if (component === 'super-admin/void-orders') {
        return 'void-orders';
    }
    if (component.startsWith('catalog/')) {
        return 'products';
    }
    if (component.startsWith('inventory/')) {
        return 'inventory';
    }
    if (component.startsWith('operations/')) {
        const destination = `ops-${component.slice('operations/'.length)}`;

        return superAdminDestinations.some((item) => item.id === destination)
            ? (destination as SuperAdminDestinationId)
            : null;
    }
    if (component === 'branches/index') {
        return 'settings';
    }
    if (component === 'workspaces/cashier-dashboard') {
        return 'cashier-dashboard';
    }
    if (component === 'workspaces/transaction-history') {
        return page.surface === 'business'
            ? 'owner-transactions'
            : 'transaction-history';
    }
    if (component === 'workspaces/kitchen') {
        return 'kitchen';
    }
    if (component === 'workspaces/customer-display') {
        return 'customer-display';
    }
    if (component === 'workspaces/owner-dashboard') {
        return 'owner-dashboard';
    }
    if (
        (component === 'workspaces/show' &&
            page.workspace === 'Cashier / POS') ||
        component === 'workspaces/order-summary'
    ) {
        return new URL(page.url, 'http://localhost').searchParams.get(
            'view',
        ) === 'qr'
            ? 'qr-orders'
            : 'pos';
    }

    return null;
}

export function superAdminSectionOf(
    destinationId: SuperAdminDestinationId | null,
): SuperAdminSectionId | null {
    return (
        superAdminDestinations.find(
            (destination) => destination.id === destinationId,
        )?.section ?? null
    );
}

/** Overview and the section holding the current page start expanded; other sections start collapsed. */
export function initialExpandedSuperAdminSections(
    activeSection: SuperAdminSectionId | null,
): SuperAdminSectionId[] {
    return superAdminSections
        .map((section) => section.id)
        .filter((id) => id === 'overview' || id === activeSection);
}

export function toggleSuperAdminSection(
    expanded: readonly SuperAdminSectionId[],
    sectionId: SuperAdminSectionId,
): SuperAdminSectionId[] {
    return expanded.includes(sectionId)
        ? expanded.filter((id) => id !== sectionId)
        : [...expanded, sectionId];
}

/** Navigating into a collapsed section re-expands it so the active destination stays visible. */
export function withActiveSuperAdminSection(
    expanded: readonly SuperAdminSectionId[],
    activeSection: SuperAdminSectionId | null,
): SuperAdminSectionId[] {
    return activeSection === null || expanded.includes(activeSection)
        ? [...expanded]
        : [...expanded, activeSection];
}
