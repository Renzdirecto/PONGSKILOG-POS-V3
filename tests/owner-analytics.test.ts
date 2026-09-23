import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    METHOD_COLORS,
    axisPeso,
    barWidth,
    categoryRowSelected,
    chartGeometry,
    durationLabel,
    nextCategorySelection,
    paymentMixSegments,
    roundedShare,
    shareLabel,
    sortProducts,
    visibleLabelIndexes,
    waitingLabel,
} from '../resources/js/lib/owner-analytics.ts';
import type {
    PaymentMixData,
    ProductRow,
} from '../resources/js/lib/owner-analytics.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const dashboard = source('pages/workspaces/owner-dashboard.tsx');
const components = source('components/owner-analytics.tsx');
const ownerShell = source('components/owner-workspace-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');
const history = source('pages/workspaces/transaction-history.tsx');
const staff = source('pages/super-admin/staff.tsx');

const product = (name: string, cents: number, quantity: number, category = 'Silog'): ProductRow => ({
    key: name,
    name,
    category,
    quantity,
    orders: quantity,
    sales: (cents / 100).toFixed(2),
    sales_cents: cents,
    share: null,
    average_price: null,
});

test('server shares and durations render as labels without re-deriving money', () => {
    assert.equal(shareLabel(3667), '36.7%');
    assert.equal(shareLabel(null), '—');
    assert.equal(roundedShare(3667), '37%');
    assert.equal(roundedShare(null), '—');
    assert.equal(durationLabel(480), '8m 00s');
    assert.equal(durationLabel(null), '—');
    assert.equal(waitingLabel(1200), '20:00');
    assert.equal(waitingLabel(3725), '1:02:05');
    assert.equal(axisPeso(123_456_700), '₱1.2M');
    assert.equal(axisPeso(1_250_000), '₱13k');
    assert.equal(axisPeso(99_900), '₱999');
});

test('chart geometry handles zero, one point and a previous series', () => {
    const empty = chartGeometry([0, 0, 0], null);
    assert.equal(empty.max, 1);
    assert.equal(empty.line, 'M0.00 100.00 L500.00 100.00 L1000.00 100.00');

    const single = chartGeometry([500], null);
    assert.equal(single.line, '');
    assert.deepEqual(single.points, [{ x: 500, y: 100 - (500 / 550) * 100 }]);

    const compared = chartGeometry([100, 200], [400, 0]);
    assert.ok(Math.abs(compared.max - 440) < 1e-9);
    assert.match(compared.previousLine, /^M0\.00 9\.09 L1000\.00 100\.00$/);
    assert.equal(compared.area.endsWith('L1000 100 L0 100 Z'), true);
});

test('bars keep a visible minimum only for non-zero values', () => {
    assert.equal(barWidth(0, 100), 0);
    assert.equal(barWidth(1, 1000), 1.5);
    assert.equal(barWidth(50, 100), 50);
    assert.equal(barWidth(5, 0), 0);
});

test('x-axis labels never crowd on mobile and always keep the last bucket', () => {
    assert.deepEqual([...visibleLabelIndexes(7, false)], [0, 1, 2, 3, 4, 5, 6]);
    assert.deepEqual([...visibleLabelIndexes(30, true)], [0, 6, 12, 18, 24, 29]);
    assert.deepEqual([...visibleLabelIndexes(0, true)], []);
});

test('the product table sorts a bounded server list and narrows by category for itself only', () => {
    const rows = [
        product('Tapsilog', 30_000, 3),
        product('Iced Tea', 15_000, 5, 'Drinks'),
        product('Longsilog', 30_000, 1),
    ];

    assert.deepEqual(
        sortProducts(rows, 'sales_desc', null).map((row) => row.name),
        ['Tapsilog', 'Longsilog', 'Iced Tea'],
    );
    assert.deepEqual(
        sortProducts(rows, 'qty_asc', null).map((row) => row.name),
        ['Longsilog', 'Tapsilog', 'Iced Tea'],
    );
    assert.deepEqual(
        sortProducts(rows, 'sales_desc', ['Drinks']).map((row) => row.name),
        ['Iced Tea'],
    );
    assert.equal(rows[0].name, 'Tapsilog');
});

test('the dashboard follows the standalone sections with real data only', () => {
    for (const title of [
        'Sales trend',
        'Payment mix',
        'Sales by category',
        'Peak sales hours',
        'Top products',
        'Inventory attention',
        'Kitchen snapshot',
        'Recent transactions',
        'Branch comparison',
        'Store Sessions',
    ]) {
        assert.match(dashboard, new RegExp(`title="${title}"`));
    }
    assert.match(dashboard, /Reporting period/);
    assert.match(dashboard, /usePoll\(30_000, \{ only: LIVE_PROPS \}\)/);
    assert.doesNotMatch(dashboard, /parseFloat|Number\(|BigInt\(|Math\.random/);
    assert.doesNotMatch(
        dashboard,
        /router\.(post|put|patch|delete)|useForm|method="post"/,
    );
    assert.match(
        dashboard,
        /transactions\(\{\s+query: \{ search: transaction\.order_number, open: transaction\.id \},\s+\}\)/,
    );
});

test('split is explained and never drawn as a third payment segment', () => {
    assert.match(components, /already included above, not added again/);
    assert.match(components, /key: 'cash' as const/);
    assert.match(components, /key: 'cashless' as const/);
    assert.doesNotMatch(components, /key: 'split' as const/);
});

/** A server payment mix as SalesAnalytics::paymentMix() presents it. */
const mix = (
    cash: [number, number | null, number | null],
    cashless: [number, number | null, number | null],
    split: [number, number | null],
): PaymentMixData => ({
    basis: 'transactions',
    methods: [
        { method: 'cash', label: 'Cash', transactions: cash[0], sales: '100.00', sales_cents: 10000, share: cash[1], share_with_split: cash[2] },
        { method: 'cashless', label: 'Cashless', transactions: cashless[0], sales: '4250.00', sales_cents: 425000, share: cashless[1], share_with_split: cashless[2] },
        { method: 'split', label: 'Split', transactions: split[0], sales: '300.00', sales_cents: 30000, share: null, share_with_split: split[1] },
    ],
    unpaid: { transactions: 0, sales: '0.00' },
});

test('the payment donut shows only mutually exclusive Cash and Cashless by default', () => {
    const view = paymentMixSegments(mix([4, 5000, 4000], [4, 5000, 4000], [2, 2000]), false);

    assert.deepEqual(view.segments.map((segment) => segment.method), ['cash', 'cashless']);
    assert.deepEqual(view.segments.map((segment) => segment.visibleShare), [5000, 5000]);
    assert.equal(view.paidTransactions, 8);
    assert.equal(view.excludedSplit, 2);
    assert.deepEqual(view.segments.map((segment) => segment.dash), ['50.00 50.00', '50.00 50.00']);
    assert.deepEqual(view.segments.map((segment) => segment.offset), ['0.00', '-50.00']);
});

test('including split adds it as its own segment and never double-counts it', () => {
    const view = paymentMixSegments(mix([4, 5000, 4000], [4, 5000, 4000], [2, 2000]), true);

    assert.deepEqual(view.segments.map((segment) => [segment.method, segment.visibleShare, segment.transactions]), [
        ['cash', 4000, 4],
        ['cashless', 4000, 4],
        ['split', 2000, 2],
    ]);
    assert.equal(view.segments.reduce((total, segment) => total + (segment.visibleShare ?? 0), 0), 10000);
    assert.equal(view.paidTransactions, 10);
    assert.equal(view.excludedSplit, 0);
    assert.deepEqual(view.segments.map((segment) => segment.offset), ['0.00', '-40.00', '-80.00']);
    assert.deepEqual(view.segments.map((segment) => segment.color), [METHOD_COLORS.cash, METHOD_COLORS.cashless, METHOD_COLORS.split]);
    assert.equal(METHOD_COLORS.split, '#8A8A8A');
});

test('payment donut edge cases stay truthful', () => {
    const empty = paymentMixSegments(mix([0, null, null], [0, null, null], [0, null]), false);
    assert.equal(empty.paidTransactions, 0);
    assert.ok(empty.segments.every((segment) => segment.dash === '0.00 100.00'));

    const cashOnly = paymentMixSegments(mix([3, 10000, 10000], [0, 0, 0], [0, 0]), false);
    assert.deepEqual(cashOnly.segments.map((segment) => segment.dash), ['100.00 0.00', '0.00 100.00']);

    const splitOnlyHidden = paymentMixSegments(mix([0, null, 0], [0, null, 0], [3, 10000]), false);
    assert.equal(splitOnlyHidden.paidTransactions, 0);
    assert.equal(splitOnlyHidden.excludedSplit, 3);
    assert.ok(splitOnlyHidden.segments.every((segment) => segment.dash === '0.00 100.00'));

    const splitOnlyShown = paymentMixSegments(mix([0, null, 0], [0, null, 0], [3, 10000]), true);
    assert.deepEqual(splitOnlyShown.segments.map((segment) => segment.dash), ['0.00 100.00', '0.00 100.00', '100.00 0.00']);
});

test('category rows select and clear exactly their categories', () => {
    const silog = { ids: ['silog-id'] };
    const others = { ids: ['drinks-id', 'addons-id'] };

    assert.equal(categoryRowSelected(silog, []), false);
    assert.equal(categoryRowSelected(silog, ['silog-id']), true);
    assert.equal(categoryRowSelected(others, ['drinks-id']), false);
    assert.equal(categoryRowSelected(others, ['addons-id', 'drinks-id']), true);
    assert.deepEqual(nextCategorySelection(silog, []), ['silog-id']);
    assert.deepEqual(nextCategorySelection(silog, ['silog-id']), []);
    assert.deepEqual(nextCategorySelection(silog, ['silog-id', 'drinks-id']), ['silog-id']);
    assert.deepEqual(nextCategorySelection(others, ['drinks-id', 'addons-id']), []);
});

test('the reports payment donut is clickable, keyboard reachable and shows exact counts', () => {
    assert.match(components, /export function PaymentMethodDonut/);
    assert.match(components, /role="button"\s+tabIndex=\{0\}/);
    assert.match(components, /onKeyDown=\{\(event\) =>\s+onSegmentKey\(event, segment\.method\)/);
    assert.match(components, /event\.key === 'Enter' \|\| event\.key === ' '/);
    assert.match(components, /onClick=\{\(\) => toggle\(segment\.method\)\}/);
    assert.match(components, /setSelected\(\(current\) => \(current === method \? null : method\)\)/);
    assert.match(components, /aria-live="polite"/);
    assert.match(components, /\{peso\(active\.sales\)\} in sales/);
    assert.match(components, /countLabel\(active\.transactions, 'transaction'\)/);
    assert.match(components, /enable Include split to show them\./);
    assert.match(components, /not in this chart until settled/);
    assert.doesNotMatch(components.slice(components.indexOf('export function PaymentMethodDonut'), components.indexOf('export function CategoryBars')), /onMouseEnter|title=/);
});

test('charts expose keyboard points and text alternatives', () => {
    assert.match(components, /onFocus=\{\(\) => setHovered\(index\)\}/);
    assert.match(components, /aria-label=\{`\$\{bucket\.full\}: /);
    assert.match(components, /<span className="sr-only">\{title\}<\/span>/);
    assert.match(components, /role="img"/);
    assert.match(components, /aria-pressed=\{value === key\}/);
});

test('the owner shell now links every owner destination', () => {
    assert.match(ownerShell, /href: canTransactions \? transactions\(\) : undefined/);
    assert.match(ownerShell, /href: canStaff \? staffIndex\(\) : undefined/);
    assert.match(ownerShell, /const isDashboard = page\.component === 'workspaces\/owner-dashboard';/);
    assert.doesNotMatch(ownerShell, /Phase 12|outside this refinement scope/);
    assert.doesNotMatch(ownerShell, /auditTrail|voidOrders|accessControl/);
});

test('owner transactions reuse the cashier page inside the management shell', () => {
    assert.match(
        layout,
        /page\.component === 'workspaces\/transaction-history' &&\s+page\.props\.surface === 'business'/,
    );
    assert.match(history, /surface: 'pos' \| 'business'/);
    assert.match(history, /const showRoute = business \? showBusiness : show;/);
    assert.match(history, /selected && editing && catalog &&/);
    assert.match(history, /canShareQr=\{selected\.operational !== false\}/);
    assert.match(history, /branchCode=\{allBranches \? item\.branch\?\.code : undefined\}/);
});

test('the staff page picks its routes from the server-provided surface', () => {
    assert.match(staff, /owner: \{ index: ownerStaffIndex, store: ownerStaffStore \}/);
    assert.match(staff, /form\.submit\(STAFF_ROUTES\[surface\]\.store\(\)/);
});
