import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    effectiveState,
    groupPermissions,
    isRedundantOverride,
    meaningfulOverrides,
    permissionDiff,
    sameOverrides,
    type PermissionMeta,
} from '../resources/js/lib/access-control.ts';
import {
    isSafeNotificationUrl,
    notificationBellLabel,
    unreadBadgeLabel,
} from '../resources/js/lib/notifications.ts';
import { reportsChannelFor } from '../resources/js/lib/realtime-refresh.ts';
import { staffChangeWarnings } from '../resources/js/lib/staff-admin.ts';

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
