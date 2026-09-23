import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    REPORT_TABS,
    customRangeError,
    reportQuery,
    SESSION_RESULTS,
    toggleFilterValue,
} from '../resources/js/lib/reports.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const page = source('pages/workspaces/reports.tsx');
const sessions = source('components/report-store-sessions.tsx');
const ownerShell = source('components/owner-workspace-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');

test('changing the period clears the Store Session and keeps a shareable query', () => {
    assert.deepEqual(
        reportQuery({ date: 'yesterday', session: 'a' }, { date: 'month' }),
        { date: 'month' },
    );
    assert.deepEqual(
        reportQuery(
            { date: 'custom', from: '2026-09-01', to: '2026-09-05' },
            {
                date: 'today',
            },
        ),
        {},
    );
    assert.deepEqual(
        reportQuery(
            { date: 'custom', from: '2026-09-01', to: '2026-09-05' },
            { session: 'b' },
        ),
        { date: 'custom', from: '2026-09-01', to: '2026-09-05', session: 'b' },
    );
    assert.deepEqual(reportQuery({ session: 'b' }, { session: undefined }), {});
});

test('order filters survive a period change and an empty group means all', () => {
    assert.deepEqual(
        reportQuery(
            { order_types: ['dine_in'], cashiers: [7], session: 'a' },
            { date: 'last_7_days' },
        ),
        { order_types: ['dine_in'], cashiers: ['7'], date: 'last_7_days' },
    );
    assert.deepEqual(
        reportQuery({ payment_methods: ['split'] }, { payment_methods: [] }),
        {},
    );
    assert.deepEqual(toggleFilterValue(['cash'], 'split'), ['cash', 'split']);
    assert.deepEqual(toggleFilterValue(['cash', 'split'], 'cash'), ['split']);
});

test('the period tabs follow the owner standalone', () => {
    assert.deepEqual(
        REPORT_TABS.map(([, label]) => label),
        ['Daily', 'Weekly', 'Monthly', 'Yearly', 'Custom'],
    );
    assert.deepEqual(
        REPORT_TABS.map(([key]) => key),
        ['today', 'last_7_days', 'last_30_days', 'last_12_months', 'custom'],
    );
});

test('custom ranges are pre-checked with the server limits', () => {
    assert.equal(customRangeError('2026-08-01', '2026-08-31'), null);
    assert.equal(
        customRangeError('2026-08-01', '2026-09-01'),
        'Choose a custom range of 31 days or fewer.',
    );
    assert.equal(
        customRangeError('2026-09-23', '2026-09-22'),
        'The end date must be on or after the start date.',
    );
    assert.equal(
        customRangeError('', '2026-09-22'),
        'Choose both a start and an end date.',
    );
    assert.equal(
        customRangeError('2026-02-30', '2026-03-01'),
        'Choose both a start and an end date.',
    );
});

test('session status is text as well as colour', () => {
    assert.deepEqual(
        Object.values(SESSION_RESULTS).map(({ label }) => label),
        ['Live', 'Balanced', 'Overage', 'Shortage', 'Not available'],
    );
});

test('the report only displays server money and exposes no mutation', () => {
    for (const file of [page, sessions]) {
        assert.doesNotMatch(file, /parseFloat|Number\(|BigInt\(|signedCents\(/);
        assert.doesNotMatch(
            file,
            /router\.(post|put|patch|delete)|useForm|method="post"/,
        );
        assert.doesNotMatch(
            file,
            /Close Store<\/|Void<\/|Add expense|Adjust inventory|Settle/,
        );
    }
    assert.match(page, /const peso = formatDecimalPeso;/);
});

test('the report has every standalone section and keeps Store Session summaries', () => {
    for (const title of [
        'Sales by category',
        'Payment method',
        'Order type',
        'Peak sales hours',
        'Sales trend',
        'Top products',
        'Product performance',
        'Kitchen performance',
        'Cashier performance',
        'Period highlights',
        'Branch comparison',
        'Store Sessions',
    ]) {
        assert.match(page, new RegExp(`title="${title}"`));
    }
    assert.match(page, /Average prep time by hour/);
    assert.match(page, /Compare previous|CompareToggle/);
    assert.match(page, /exportReport\.url\(\{ query: currentQuery \}\)/);
    assert.match(page, /window\.print\(\)/);
    assert.match(page, /Reset all/);
});

test('filters, dialogs and tables are labelled for assistive technology', () => {
    assert.match(page, /label="Business date"/);
    assert.match(page, /<span className=\{labelClass\}>Store Session<\/span>/);
    assert.match(page, /type="date"/);
    assert.match(page, /aria-label="Report from date"/);
    assert.match(page, /<DialogTitle[\s\S]{0,80}Report filters/);
    assert.match(page, /aria-label=\{`Remove filter \$\{chip\.label\}`\}/);
    assert.match(page, /scope="col"/);
    assert.match(sessions, /<DialogTitle[\s\S]{0,80}Store Session/);
    assert.match(sessions, /<DialogDescription/);
    assert.match(sessions, /LIVE · figures are provisional/);
    assert.match(sessions, /Unclaimed QR orders archived/);
});

test('category is honestly limited to the product table because payments are per order', () => {
    assert.match(page, /Category is not a report-wide filter/);
    assert.match(page, /this table only/);
    assert.match(page, /Order filters do not apply here/);
});

test('Owner and Super Admin render the report inside their management shells', () => {
    assert.match(
        ownerShell,
        /label: 'Reports',[\s\S]{0,120}href: reports\(\),\s+active: isReports,/,
    );
    assert.match(
        ownerShell,
        /const isReports = page\.component === 'workspaces\/reports';/,
    );
    assert.match(layout, /page\.component === 'workspaces\/reports' \|\|/);
});
