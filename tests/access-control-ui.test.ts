import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    effectiveState,
    groupPermissions,
    isRedundantOverride,
    meaningfulOverrides,
    normalizeRoleName,
    permissionDiff,
    permissionsForScope,
    roleNameError,
    sameOverrides,
    type PermissionMeta,
} from '../resources/js/lib/access-control.ts';
import {
    isSafeNotificationUrl,
    notificationBellLabel,
    unreadBadgeLabel,
} from '../resources/js/lib/notifications.ts';
import { reportsChannelFor } from '../resources/js/lib/realtime-refresh.ts';
import {
    groupStaffRoles,
    staffChangeWarnings,
} from '../resources/js/lib/staff-admin.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

const roles = [
    { name: 'cashier', label: 'Cashier', business_wide: false },
    { name: 'kitchen_staff', label: 'Kitchen Staff', business_wide: false },
    { name: 'owner', label: 'Owner', business_wide: true },
    { name: 'super_admin', label: 'Super Admin', business_wide: true },
];

test('the effective result mirrors inherit, allow and deny over the role baseline', () => {
    assert.equal(effectiveState(true, 'inherit'), 'role');
    assert.equal(effectiveState(false, 'inherit'), 'none');
    assert.equal(effectiveState(false, 'allow'), 'custom');
    assert.equal(effectiveState(true, 'deny'), 'removed');
    assert.equal(isRedundantOverride(true, 'allow'), true);
    assert.equal(isRedundantOverride(false, 'deny'), true);
    assert.equal(isRedundantOverride(false, 'allow'), false);
});

test('only meaningful overrides are submitted and compared', () => {
    const submitted = meaningfulOverrides(
        {
            'reports.view': 'allow',
            'pos.access': 'allow',
            'kitchen.access': 'deny',
            'store.open_close': 'deny',
            'transactions.view': 'inherit',
        },
        ['pos.access', 'store.open_close', 'transactions.view'],
    );

    assert.deepEqual(submitted, {
        'reports.view': 'allow',
        'store.open_close': 'deny',
    });
    assert.equal(sameOverrides(submitted, { ...submitted }), true);
    assert.equal(sameOverrides(submitted, { 'reports.view': 'allow' }), false);
});

test('permissions are grouped by server categories and role diffs are exact', () => {
    const permissions: PermissionMeta[] = [
        {
            key: 'pos.access',
            label: 'POS',
            description: '',
            category: 'operations',
            scope: 'branch',
            super_admin_only: false,
        },
        {
            key: 'reports.view',
            label: 'Reports',
            description: '',
            category: 'management',
            scope: 'either',
            super_admin_only: false,
        },
    ];
    const groups = groupPermissions(permissions, {
        operations: 'Operations',
        management: 'Management',
        control: 'Control',
    });

    assert.deepEqual(
        groups.map((group) => [
            group.label,
            group.permissions.map((item) => item.key),
        ]),
        [
            ['Operations', ['pos.access']],
            ['Management', ['reports.view']],
        ],
    );
    assert.deepEqual(
        permissionDiff(
            ['pos.access', 'store.open_close'],
            ['pos.access', 'reports.view'],
        ),
        {
            added: ['reports.view'],
            removed: ['store.open_close'],
        },
    );
});

test('the unread badge shows only a real positive count', () => {
    assert.equal(unreadBadgeLabel(0), null);
    assert.equal(unreadBadgeLabel(null), null);
    assert.equal(unreadBadgeLabel(3), '3');
    assert.equal(unreadBadgeLabel(140), '99+');
    assert.equal(notificationBellLabel(0), 'Notifications, no unread');
    assert.equal(notificationBellLabel(4), 'Notifications, 4 unread');
});

test('notification links must be same-app relative paths', () => {
    assert.equal(isSafeNotificationUrl('/workspaces/operations/stock'), true);
    assert.equal(isSafeNotificationUrl('//evil.example/phish'), false);
    assert.equal(isSafeNotificationUrl('/\\evil.example'), false);
    assert.equal(isSafeNotificationUrl('https://evil.example'), false);
    assert.equal(isSafeNotificationUrl(null), false);
});

test('branch-scoped report viewers listen only on their branch channel', () => {
    assert.equal(reportsChannelFor(true, 'branch-1'), 'reports');
    assert.equal(reportsChannelFor(true, null), 'reports');
    assert.equal(
        reportsChannelFor(false, 'branch-1'),
        'branch.branch-1.reports',
    );
});

test('high-impact staff edits require a plain-language confirmation', () => {
    const member = {
        role: 'cashier',
        is_active: true,
        branch_ids: ['main', 'qave'],
        custom_access_count: 2,
        business_wide: false,
    };
    const branchNames = { main: 'Main', qave: 'Qave' };

    assert.deepEqual(
        staffChangeWarnings({
            member,
            next: {
                role: 'cashier',
                is_active: true,
                branch_ids: ['main', 'qave'],
            },
            roles,
            branchNames,
        }),
        [],
    );

    const promotion = staffChangeWarnings({
        member,
        next: { role: 'super_admin', is_active: true, branch_ids: [] },
        roles,
        branchNames,
    });
    assert.match(
        promotion[0],
        /Cashier to Super Admin\. A Super Admin has full access/,
    );
    assert.match(promotion[1], /2 custom permissions will be removed/);
    assert.match(promotion[2], /Branch assignments are cleared/);

    const deactivateAndRemove = staffChangeWarnings({
        member,
        next: { role: 'cashier', is_active: false, branch_ids: ['main'] },
        roles,
        branchNames,
    });
    assert.deepEqual(deactivateAndRemove, [
        'The account is deactivated: it cannot sign in and open sessions are signed out.',
        'Branch access removed: Qave.',
    ]);
});

test('branch staff with custom reports see reports in the operational shell, never the owner shell', () => {
    const layout = source('layouts/workspace-layout.tsx');

    assert.match(
        layout,
        /page\.component === 'workspaces\/reports' && !branchContext\.businessWide/,
    );
    assert.match(
        layout,
        /auth\.permissions\.includes\('reports\.view'\) &&\s*!branchContext\.businessWide/,
    );
});

test('the staff edit sheet keeps the employee id read-only and resets passwords only through confirmation', () => {
    const dialogs = source('components/staff-account-dialogs.tsx');

    assert.match(dialogs, /The Employee ID is a permanent identity/);
    assert.doesNotMatch(dialogs, /setData\('employee_id'/);
    assert.match(dialogs, /Confirm password reset/);
    assert.match(dialogs, /_method: 'put'/);
});

test('custom role names are normalized and checked like the server', () => {
    const taken = ['Owner', 'Super Admin', 'Branch Supervisor'];

    assert.equal(normalizeRoleName('  Shift   Lead '), 'Shift Lead');
    assert.equal(roleNameError('   ', 40, taken), 'Role name is required.');
    assert.equal(
        roleNameError('a'.repeat(41), 40, taken),
        'Use at most 40 characters.',
    );
    assert.match(
        roleNameError('<b>Boss</b>', 40, taken) ?? '',
        /letters, numbers/,
    );
    assert.match(
        roleNameError('branch  SUPERVISOR', 40, taken) ?? '',
        /already exists/,
    );
    assert.match(roleNameError('owner', 40, taken) ?? '', /already exists/);
    assert.equal(roleNameError('Kitchen Lead (AM)', 40, taken), null);
});

test('switching a custom role scope keeps only permissions that scope may hold', () => {
    assert.deepEqual(
        permissionsForScope(['pos.access', 'reports.view', 'products.manage'], {
            'pos.access': null,
            'reports.view': null,
            'products.manage': 'Business-wide only.',
        }),
        ['pos.access', 'reports.view'],
    );
});

test('custom roles are grouped apart from system roles and explained on a role change', () => {
    const withCustom = [
        ...roles,
        {
            name: 'custom_7',
            label: 'Branch Supervisor',
            business_wide: false,
            custom: true,
        },
        {
            name: 'custom_8',
            label: 'Area Manager',
            business_wide: true,
            custom: true,
        },
    ];
    const grouped = groupStaffRoles(withCustom);
    assert.deepEqual(
        grouped.system.map((role) => role.name),
        ['cashier', 'kitchen_staff', 'owner', 'super_admin'],
    );
    assert.deepEqual(
        grouped.custom.map((role) => role.name),
        ['custom_7', 'custom_8'],
    );

    const member = {
        role: 'cashier',
        is_active: true,
        branch_ids: ['main'],
        custom_access_count: 2,
        business_wide: false,
    };
    const toBranchRole = staffChangeWarnings({
        member,
        next: { role: 'custom_7', is_active: true, branch_ids: ['main'] },
        roles: withCustom,
        branchNames: { main: 'Main' },
    });
    assert.match(toBranchRole[0], /Branch Supervisor custom role baseline/);
    assert.match(toBranchRole[1], /2 custom permissions will be removed/);

    const toBusinessRole = staffChangeWarnings({
        member,
        next: { role: 'custom_8', is_active: true, branch_ids: [] },
        roles: withCustom,
        branchNames: { main: 'Main' },
    });
    assert.ok(
        toBusinessRole.some((warning) =>
            warning.includes('business-wide access'),
        ),
    );
    assert.ok(
        toBusinessRole.includes(
            'Branch assignments are cleared (business-wide role).',
        ),
    );
});

test('operational layout and history rely on permissions, not role names, for custom roles', () => {
    const layout = source('layouts/workspace-layout.tsx');
    assert.ok(layout.includes("auth.permissions.includes('pos.access')"));
    assert.ok(!layout.includes("role === 'cashier_kitchen'"));
    assert.ok(layout.includes('auth.roleLabel'));
    assert.ok(
        source('pages/workspaces/transaction-history.tsx').includes(
            "const canManageKitchen = auth.permissions.includes('kitchen.access')",
        ),
    );
});

test('business-wide custom roles reach branch operations through permissions and a concrete branch', () => {
    const shell = source('components/owner-workspace-shell.tsx');
    assert.match(
        source('lib/management-navigation.ts'),
        /permissions\.includes\(destination\.permission\)/,
    );
    assert.match(
        shell,
        /branchContext\.current\s*\? route\s*: selectBranch\(\{ query: \{ redirect: route\.url \} \}\)/,
    );
    const layout = source('layouts/workspace-layout.tsx');
    assert.match(
        layout,
        /!isSuperAdmin &&\s*branchContext\.businessWide &&\s*MANAGEMENT_PERMISSIONS\.some/,
    );
    const builder = source('components/custom-role-dialogs.tsx');
    assert.ok(builder.includes('Access is limited to assigned Branches.'));
    assert.ok(
        builder.includes(
            'Access can span all Branches. Branch operations still require selecting a specific Branch.',
        ),
    );
});
