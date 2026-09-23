import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    activeSuperAdminDestination,
    initialExpandedSuperAdminSections,
    superAdminDestinations,
    superAdminNavigation,
    superAdminSectionOf,
    toggleSuperAdminSection,
    withActiveSuperAdminSection,
} from '../resources/js/lib/super-admin-navigation.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const shell = source('components/super-admin-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');
const staffPage = source('pages/super-admin/staff.tsx');
const placeholderPage = source('pages/super-admin/placeholder.tsx');

const superAdminPermissions = [
    'pos.access',
    'qr_orders.access',
    'transactions.view',
    'store.open_close',
    'store_expenses.manage',
    'kitchen.access',
    'customer_display.launch',
    'reports.view',
    'products.manage',
    'inventory.manage',
    'staff.manage',
    'settings.manage',
    'audit.view',
    'void_orders.manage',
    'access_control.manage',
];

test('super admin navigation exposes the four required sections in order', () => {
    const navigation = superAdminNavigation(superAdminPermissions);

    assert.deepEqual(
        navigation.map(({ section, destinations }) => [
            section.label,
            destinations.map((destination) => destination.label),
        ]),
        [
            ['Overview', ['Dashboard', 'Notifications']],
            [
                'Cashier + Kitchen',
                [
                    'Cashier Dashboard',
                    'POS / Orders',
                    'QR Orders',
                    'Transaction History',
                    'Kitchen',
                    'Customer Display',
                ],
            ],
            ['Owner', ['Owner Dashboard', 'Reports', 'Products', 'Inventory']],
            [
                'Control',
                [
                    'Audit Trail',
                    'Void Orders',
                    'Staff',
                    'Access Control',
                    'Settings',
                ],
            ],
        ],
    );
});

test('existing destinations point at real routes and only unbuilt pages are planned', () => {
    const routes = Object.fromEntries(
        superAdminDestinations.map((destination) => [
            destination.id,
            [destination.routeName, destination.availability],
        ]),
    );

    assert.deepEqual(routes, {
        dashboard: ['workspaces.super-admin', 'live'],
        notifications: ['super-admin.notifications', 'planned'],
        'cashier-dashboard': ['workspaces.cashier-dashboard', 'live'],
        pos: ['workspaces.cashier', 'live'],
        'qr-orders': ['workspaces.cashier', 'live'],
        'transaction-history': ['workspaces.transaction-history', 'live'],
        kitchen: ['workspaces.kitchen', 'live'],
        'customer-display': ['workspaces.customer-display', 'live'],
        'owner-dashboard': ['workspaces.owner', 'live'],
        reports: ['super-admin.reports', 'planned'],
        products: ['products.index', 'live'],
        inventory: ['inventory.index', 'live'],
        'audit-trail': ['workspaces.audit-trail', 'live'],
        'void-orders': ['workspaces.void-orders', 'live'],
        staff: ['super-admin.staff.index', 'live'],
        'access-control': ['super-admin.access-control', 'planned'],
        settings: ['branches.index', 'live'],
    });
});

test('navigation is filtered by the permission each destination requires', () => {
    const ownerPermissions = [
        'transactions.view',
        'reports.view',
        'products.manage',
        'inventory.manage',
        'staff.manage',
        'settings.manage',
    ];
    const labels = superAdminNavigation(ownerPermissions).flatMap(
        ({ destinations }) => destinations.map((item) => item.label),
    );

    assert.equal(labels.includes('Staff'), false);
    assert.equal(labels.includes('Audit Trail'), false);
    assert.equal(labels.includes('Access Control'), false);
    assert.equal(labels.includes('Products'), true);
});

test('the active destination follows the rendered page', () => {
    const cases: [Parameters<typeof activeSuperAdminDestination>[0], string][] =
        [
            [{ component: 'super-admin/dashboard', url: '/' }, 'dashboard'],
            [
                {
                    component: 'super-admin/placeholder',
                    url: '/',
                    destination: 'access-control',
                },
                'access-control',
            ],
            [{ component: 'super-admin/staff', url: '/' }, 'staff'],
            [{ component: 'catalog/modifiers', url: '/' }, 'products'],
            [{ component: 'inventory/movements', url: '/' }, 'inventory'],
            [{ component: 'branches/index', url: '/' }, 'settings'],
            [
                { component: 'workspaces/show', url: '/', workspace: 'Owner' },
                'owner-dashboard',
            ],
            [
                {
                    component: 'workspaces/show',
                    url: '/workspaces/cashier?view=qr',
                    workspace: 'Cashier / POS',
                },
                'qr-orders',
            ],
        ];

    for (const [page, expected] of cases) {
        assert.equal(activeSuperAdminDestination(page), expected);
    }
    assert.equal(
        activeSuperAdminDestination({
            component: 'super-admin/placeholder',
            url: '/',
            destination: 'staff',
        }),
        null,
    );
});

test('sections collapse and the active section re-expands on navigation', () => {
    const initial = initialExpandedSuperAdminSections(
        superAdminSectionOf('audit-trail'),
    );
    assert.deepEqual(initial, ['overview', 'control']);

    const collapsed = toggleSuperAdminSection(initial, 'control');
    assert.deepEqual(collapsed, ['overview']);
    assert.deepEqual(toggleSuperAdminSection(collapsed, 'operations'), [
        'overview',
        'operations',
    ]);
    assert.deepEqual(
        withActiveSuperAdminSection(collapsed, superAdminSectionOf('products')),
        ['overview', 'owner'],
    );
    assert.deepEqual(withActiveSuperAdminSection(initial, null), initial);
});

test('the shell renders accessible collapsible groups bound to real routes', () => {
    assert.match(shell, /aria-expanded=\{isExpanded\}/);
    assert.match(shell, /aria-controls=\{regionId\}/);
    assert.match(shell, /hidden=\{!isExpanded\}/);
    assert.match(shell, /aria-current=\{active \? 'page' : undefined\}/);
    assert.match(shell, /staff: \{ icon: Users, href: staffIndex\(\) \}/);
    assert.match(shell, /pos: \{ icon: UtensilsCrossed, href: cashier\(\) \}/);
    assert.match(shell, /Choose a Branch/);
    assert.match(shell, /min-h-11/);
    // Absolutely positioned content (sr-only labels) must scroll inside main, never extend the document.
    assert.match(shell, /<main className="owner-scrollbar relative /);
    assert.match(
        source('components/owner-workspace-shell.tsx'),
        /<main className="owner-scrollbar relative /,
    );
    assert.doesNotMatch(shell, /unread|badgeCount/i);
    assert.match(layout, /<SuperAdminShell>\{children\}<\/SuperAdminShell>/);
    assert.match(layout, /Back to Super Admin Control Center/);
});

test('planned pages and the staff form never fake controls or echo credentials', () => {
    assert.match(placeholderPage, /Planned/);
    assert.doesNotMatch(placeholderPage, /type="checkbox"|role="switch"/);
    assert.match(staffPage, /form\.reset\('password', 'password_confirmation'\)/);
    assert.match(staffPage, /All branches \/ business-wide/);
    assert.doesNotMatch(staffPage, /must change|first login|invite/i);
});
