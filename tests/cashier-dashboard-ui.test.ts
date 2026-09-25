import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(
    new URL(
        '../resources/js/pages/workspaces/cashier-dashboard.tsx',
        import.meta.url,
    ),
    'utf8',
);
const layout = readFileSync(
    new URL('../resources/js/layouts/workspace-layout.tsx', import.meta.url),
    'utf8',
);

test('operational sidebar unlocks Dashboard for POS staff', () => {
    assert.match(
        layout,
        /label: 'Dashboard',\s+icon: LayoutDashboard,\s+available: auth\.permissions\.includes\('pos\.access'\),\s+href: cashierDashboard\(\),\s+active: isDashboard,/,
    );
    assert.match(
        layout,
        /const isOperational =\s+isPos \|\| isKitchen \|\| isHistory \|\| isDashboard \|\| isBranchReports;/,
    );
});

test('dashboard quick actions reuse existing workspace routes', () => {
    assert.match(page, /label: 'Go to POS',[\s\S]{0,120}href: cashier\(\)/);
    assert.match(
        page,
        /label: 'Transaction History',[\s\S]{0,120}href: transactionHistory\(\)/,
    );
    assert.match(
        page,
        /canUseKitchen\s*\?[\s\S]{0,200}label: 'Kitchen',[\s\S]{0,120}href: kitchen\(\)/,
    );
    assert.match(
        page,
        /const canUseKitchen = auth\.permissions\.includes\('kitchen\.access'\);/,
    );
});

test('dashboard shows backend money without browser arithmetic', () => {
    assert.doesNotMatch(page, /\bcents\(/);
    assert.doesNotMatch(page, /parseFloat|Number\(summary|BigInt\(/);
    assert.match(page, /pesos\(summary\.sales\)/);
});

test('cash and cashless hints describe net channel sales, not raw payment legs', () => {
    assert.match(page, /hint: summary \? channelHint\('Cash'\) : closedHint/);
    assert.match(page, /splitHint \?\? channelHint\('Cashless'\)/);
    assert.match(page, /net of corrections/);
    assert.doesNotMatch(page, /payment legs'/);
});

test('dashboard refreshes its projection from branch realtime signals', () => {
    for (const event of [
        '.order.committed',
        '.order.voided',
        '.kitchen.status_changed',
        '.store.expense_recorded',
        '.inventory.changed',
    ]) {
        assert.ok(page.includes(`'${event}'`), event);
    }
    assert.match(
        page,
        /const DASHBOARD_REFRESH_PROPS = \['dashboard', 'storeContext'\];/,
    );
    assert.doesNotMatch(page, /setInterval\([^)]*router|usePoll/);
});

test('store session details reuse the layout dialog', () => {
    assert.match(page, /useStoreSessionDetails\(\)/);
    assert.match(layout, /<StoreSessionDetailsContext\.Provider/);
    assert.doesNotMatch(page, /<StoreSessionDetailsDialog/);
});
