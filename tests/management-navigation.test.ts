import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { auditActorName } from '../resources/js/lib/audit-actions.ts';
import {
    activeManagementDestination,
    identitySubtitle,
    managementNavigation,
    pinnedManagementDestinations,
    restoredSidebarCollapsed,
    storedSidebarValue,
} from '../resources/js/lib/management-navigation.ts';
import { staffPositionLabel } from '../resources/js/lib/staff-admin.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const shell = source('components/owner-workspace-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');

const operationsPages = [
    'Pamalengke Plans',
    'Overview',
    'Ingredients',
    'Recipes',
    'Ingredient Stock',
    'Pamamalengke',
    'Purchases',
];

const sections = (permissions: string[], businessWide = true) =>
    managementNavigation(permissions, { businessWide }).map(
        ({ section, destinations }) => [
            section.label,
            destinations.map((destination) => destination.label),
        ],
    );

test('an area manager sees only the pages its permissions open, grouped in the approved sections', () => {
    assert.deepEqual(
        sections([
            'pos.access',
            'qr_orders.access',
            'transactions.view',
            'reports.view',
            'products.manage',
            'operations.manage',
            'settings.manage',
        ]),
        [
            ['Overview', ['Dashboard']],
            ['Store Operations', ['POS', 'QR Orders']],
            ['Sales', ['Transactions', 'Reports']],
            ['Catalog', ['Products']],
            ['Operations', operationsPages],
            ['Administration', ['Settings']],
        ],
    );
});

test('every permitted store operation is listed and the section is never called Cashier + Kitchen', () => {
    assert.deepEqual(
        sections([
            'pos.access',
            'qr_orders.access',
            'kitchen.access',
            'customer_display.launch',
        ]),
        [['Store Operations', ['POS', 'QR Orders', 'Kitchen', 'Display']]],
    );
    assert.deepEqual(sections(['kitchen.access']), [
        ['Store Operations', ['Kitchen']],
    ]);
    assert.doesNotMatch(shell, /Cashier \+ Kitchen|Branch operations/);
});

test('inventory and operations follow their own permissions', () => {
    assert.deepEqual(sections(['inventory.manage']), [
        ['Catalog', ['Inventory']],
    ]);
    assert.deepEqual(sections(['operations.manage']), [
        ['Operations', operationsPages],
    ]);
});

test('settings needs business-wide scope and an account without access sees no destination', () => {
    assert.deepEqual(sections(['settings.manage'], false), []);
    assert.deepEqual(sections([]), []);
});

test('pages without access are never rendered as disabled no-access rows', () => {
    assert.doesNotMatch(shell, /No access|unavailableReason|disabled/);
    assert.doesNotMatch(layout, /Coming later/);
    assert.match(layout, /\]\.filter\(\(item\) => item\.available\);/);
});

test('the mobile dock pins the preferred pages the account can open, then the next visible ones', () => {
    const pinned = (permissions: string[]) =>
        pinnedManagementDestinations(
            managementNavigation(permissions, {
                businessWide: true,
            }).flatMap((group) => group.destinations),
        ).map((destination) => destination.label);

    assert.deepEqual(
        pinned(['reports.view', 'products.manage', 'inventory.manage']),
        ['Dashboard', 'Products', 'Inventory'],
    );
    assert.deepEqual(pinned(['pos.access', 'operations.manage']), [
        'POS',
        'QR Orders',
        'Pamalengke Plans',
    ]);
});

test('management pages resolve to their destination; store operations never mark a management page', () => {
    assert.equal(
        activeManagementDestination({
            component: 'workspaces/transaction-history',
            surface: 'business',
        }),
        'transactions',
    );
    assert.equal(
        activeManagementDestination({ component: 'operations/stock' }),
        'stock',
    );
    assert.equal(
        activeManagementDestination({ component: 'workspaces/show' }),
        null,
    );
});

test('the desktop sidebar collapses through an accessible toggle and remembers the choice on this device', () => {
    assert.equal(restoredSidebarCollapsed('collapsed'), true);
    assert.equal(restoredSidebarCollapsed(null), false);
    assert.equal(restoredSidebarCollapsed('garbage'), false);
    assert.equal(storedSidebarValue(true), 'collapsed');
    assert.equal(storedSidebarValue(false), 'expanded');
    assert.match(shell, /aria-expanded=\{!collapsed\}/);
    assert.match(shell, /collapsed \? 'Expand sidebar' : 'Collapse sidebar'/);
    assert.match(
        shell,
        /aria-label=\{variant === 'rail' \? destination\.label : undefined\}/,
    );
    assert.match(shell, /\$\{collapsed \? 'w-\[76px\]' : 'w-\[248px\]'\}/);
});

test('the sidebar footer names the person by position and falls back to the role label', () => {
    assert.equal(
        identitySubtitle('Area Manager', 'Regional Lead'),
        'Area Manager',
    );
    assert.equal(identitySubtitle('   ', 'Regional Lead'), 'Regional Lead');
    assert.equal(identitySubtitle(null, null), 'Staff');
    assert.match(
        shell,
        /identitySubtitle\(\s*auth\.user\?\.position,\s*workspaceLabel,?\s*\)/,
    );
});

test('staff cards show a position unless it only repeats the role label', () => {
    assert.equal(
        staffPositionLabel('Area Manager', ['Regional Lead']),
        'Area Manager',
    );
    assert.equal(staffPositionLabel('area manager', ['Area Manager']), null);
    assert.equal(staffPositionLabel('  ', ['Cashier']), null);
    assert.equal(staffPositionLabel(null, ['Cashier']), null);
});

test('audit actors read "Name · Position" without an empty separator', () => {
    assert.equal(
        auditActorName({
            name: 'Juan Dela Cruz',
            position: 'Branch Supervisor',
        }),
        'Juan Dela Cruz · Branch Supervisor',
    );
    assert.equal(
        auditActorName({ name: 'Juan Dela Cruz', position: null }),
        'Juan Dela Cruz',
    );
    assert.equal(auditActorName(null), 'System process');
});
