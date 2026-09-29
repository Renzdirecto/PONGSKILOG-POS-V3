import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { auditActionLabel } from '../resources/js/lib/audit-actions.ts';
import {
    ATTENTION_TONES,
    storeOpenLabel,
} from '../resources/js/lib/executive-dashboard.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

test('store status reads the real open session in Philippine time', () => {
    const branch = { id: 'b1', name: 'Main', code: 'MAIN' };

    assert.equal(
        storeOpenLabel({
            branch,
            open: false,
            opened_at: null,
            opened_by: null,
        }),
        'No open Store Session',
    );
    assert.equal(
        storeOpenLabel({
            branch,
            open: true,
            opened_at: '2026-09-23T08:05:00+08:00',
            opened_by: 'Juan',
        }),
        'Open since 8:05 AM · Juan',
    );
});

test('attention tones use red only for critical items and violet for security', () => {
    assert.match(ATTENTION_TONES.critical.panel, /red/);
    assert.match(ATTENTION_TONES.warning.panel, /amber/);
    assert.match(ATTENTION_TONES.security.panel, /violet/);
    assert.doesNotMatch(ATTENTION_TONES.notice.panel, /red|amber/);
});

test('audit actions have readable labels, including custom roles', () => {
    assert.equal(
        auditActionLabel('access.custom_role_created'),
        'Custom role created',
    );
    assert.equal(
        auditActionLabel('access.custom_role_archived'),
        'Custom role archived',
    );
    assert.equal(auditActionLabel('inventory.adjusted'), 'Inventory Adjusted');
});

test('the executive dashboard reuses the canonical analytics components and existing realtime signals', () => {
    const page = source('pages/super-admin/dashboard.tsx');

    for (const component of [
        'KpiGrid',
        'TrendChart',
        'PaymentMethodDonut',
        'TopProductBars',
        'BranchComparison',
    ]) {
        assert.ok(page.includes(component), component);
    }
    assert.ok(page.includes('useReportsRealtimeRefresh(REPORT_PROPS'));
    assert.ok(page.includes('useNotificationsPageRefresh('));
    assert.ok(!page.includes('useEcho'), 'no extra channel subscription');
    assert.ok(
        !/Math\.random|sample|placeholder/i.test(page),
        'no fabricated data',
    );
});
