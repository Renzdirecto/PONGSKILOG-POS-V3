import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    REPORT_TABS,
    appliedSelection,
    customRangeError,
    draftSelection,
    reportQuery,
    SESSION_RESULTS,
    toggleFilterValue,
} from '../resources/js/lib/reports.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const page = source('pages/workspaces/reports.tsx');
const sessions = source('components/report-store-sessions.tsx');
const components = source('components/owner-analytics.tsx');
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
    assert.match(page, /<DialogTitle[\s\S]{0,120}Filter this report/);
    assert.match(page, /aria-label=\{`Remove filter \$\{chip\.label\}`\}/);
    assert.match(page, /scope="col"/);
    assert.match(sessions, /<DialogTitle[\s\S]{0,80}Store Session/);
    assert.match(sessions, /<DialogDescription/);
    assert.match(sessions, /LIVE · figures are provisional/);
    assert.match(sessions, /Unclaimed QR orders archived/);
});

test('the filter dialog opens every unfiltered group fully checked and applies all or none as no filter', () => {
    const options = [{ value: 'cash' }, { value: 'cashless' }, { value: 'split' }];

    assert.deepEqual(draftSelection([], options), ['cash', 'cashless', 'split']);
    assert.deepEqual(draftSelection(['split'], options), ['split']);
    assert.deepEqual(appliedSelection(['cash', 'cashless', 'split'], options), []);
    assert.deepEqual(appliedSelection([], options), []);
    assert.deepEqual(appliedSelection(['split', 'cash'], options), ['cash', 'split']);
    assert.deepEqual(appliedSelection(['gone'], options), []);
});

test('category filters stay in the shareable query and clear like every other filter', () => {
    assert.deepEqual(
        reportQuery(
            { categories: ['c1'], order_types: ['dine_in'] },
            { date: 'last_7_days' },
        ),
        { categories: ['c1'], order_types: ['dine_in'], date: 'last_7_days' },
    );
    assert.deepEqual(reportQuery({ categories: ['c1'] }, { categories: [] }), {});
});

test('the filter dialog matches the owner standalone structure', () => {
    const dialog = page.slice(page.indexOf('function FilterDialog('));

    assert.match(dialog, /Analytics filter/);
    assert.match(dialog, /Filter this report/);
    assert.match(dialog, /overlayClassName="bg-\[rgba\(17,17,17,0\.44\)\]"/);
    assert.match(dialog, /showCloseButton=\{false\}/);
    assert.match(dialog, /<DialogClose\s+aria-label="Close"/);
    assert.match(dialog, /top-auto bottom-0 left-0 [^"]*rounded-t-\[20px\] rounded-b-none/);
    assert.match(dialog, /md:max-h-\[min\(92dvh,940px\)\] md:max-w-\[560px\]/);
    assert.match(dialog, /shadow-\[0_30px_70px_rgba\(0,0,0,0\.3\)\]/);
    assert.match(dialog, /border-b border-\[#e5e5e5\] px-4 py-3\.5/);
    assert.match(dialog, /\[grid-template-columns:repeat\(auto-fit,minmax\(150px,1fr\)\)\]/);
    assert.match(dialog, /role="checkbox"\s+aria-checked=\{on\}/);
    assert.match(dialog, /inline-flex size-5 shrink-0 items-center justify-center rounded-md border/);
    assert.match(dialog, /Select all/);
    assert.match(dialog, /pb-\[calc\(14px\+env\(safe-area-inset-bottom,0px\)\)\]/);
    assert.match(dialog, /h-\[52px\] flex-\[0_1_auto\][^"]*border-\[#949494\][\s\S]{0,200}Reset/);
    assert.match(dialog, /h-\[52px\] flex-\[1_1_auto\][^"]*bg-\[#111\][^"]*"\s*>\s*<Check[^>]*\/>\s*Apply filters/);
    assert.deepEqual(
        [...dialog.matchAll(/title: '([^']+)'/g)].map((match) => match[1]),
        ['Category', 'Order type', 'Payment method', 'Cashier'],
    );
    assert.match(dialog, /scope: 'Product views only'/);
    assert.match(dialog, /onClick=\{\(\) => setDraft\(everything\(\)\)\}/);
    assert.match(dialog, /categories: appliedSelection\(\s+draft\.categories,/);
    assert.match(dialog, /cashiers: appliedSelection\(\s+draft\.cashiers,/);
});

test('active chips mirror the server filters, including category, and Reset all clears them', () => {
    assert.match(page, /label: `Category: \$\{analytics\.filters\.categories/);
    assert.match(page, /onClick=\{\(\) => visit\(\{ \[chip\.key\]: \[\] \}\)\}/);
    assert.match(
        page,
        /order_types: \[\],\s+payment_methods: \[\],\s+cashiers: \[\],\s+categories: \[\],/,
    );
    assert.match(page, /onApply=\{\(next\) => \{\s+setFiltersOpen\(false\);\s+visit\(next\);/);
});

test('sales by category rows filter only the product views and say so', () => {
    assert.match(page, /selected=\{selectedCategories\}/);
    assert.match(page, /nextCategorySelection\(\s+category,\s+selectedCategories,\s+\)/);
    assert.match(page, /<CategoryScopePill/);
    assert.match(page, /Category: \{label\}/);
    assert.match(page, /onClear=\{\(\) => pickCategories\(\[\]\)\}/);
    const prose = page.replace(/\s+/g, ' ');
    assert.match(prose, /Sales, payments and collections are unchanged\./);
    assert.match(prose, /Grouped by each product’s current category — order items do not record the category at the time of sale\./);
    assert.match(prose, /a category never filters Cash, Cashless or other money figures\./);
    assert.match(page, /Category: \$\{categoryScope\}/);
    assert.match(page, /toggleCategoryChip\(\s+category\.value,\s+\)/);
    assert.match(page, /Order filters do not apply here/);
    assert.doesNotMatch(page, /tableCategories|this table only/);
    assert.match(components, /aria-pressed=\{on\}\s+onClick=\{\(\) => onPick\(category\)\}/);
});

test('category selection never feeds the payment method, KPI or collection figures', () => {
    const donut = page.slice(page.indexOf('title="Payment method"'), page.indexOf('title="Order type"'));

    assert.match(donut, /mix=\{analytics\.payment_mix\}/);
    assert.doesNotMatch(donut, /categor/i);
    assert.match(page, /<KpiGrid analytics=\{analytics\} comparison=\{comparison\} \/>/);
    assert.doesNotMatch(page, /<PaymentMix\b/);
});

test('the payment method card has a default-off Include split checkbox in its header', () => {
    assert.match(page, /const \[includeSplit, setIncludeSplit\] = useState\(false\);/);
    assert.match(
        page,
        /action=\{\s+<label[^>]*>\s+<input\s+type="checkbox"\s+checked=\{includeSplit\}/,
    );
    assert.match(page, /Include split\s+<\/label>/);
    assert.match(page, /includeSplit=\{includeSplit\}/);
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
    assert.match(
        layout,
        /\(page\.component === 'workspaces\/reports' && !isBranchReports\) \|\|/,
    );
});

test('report filter chips, reset and the custom range fit phones with 44px targets', () => {
    assert.match(page, /rounded-full border border-\[#111\] bg-white px-\[11px\] text-\[11\.5px\] font-semibold focus-visible:ring-2 focus-visible:ring-\[#111\] focus-visible:outline-none md:min-h-8/);
    assert.match(page, /inline-flex min-h-11 items-center rounded-full px-\[11px\][^"]+md:min-h-8"\s+>\s+Reset all/);
    assert.match(page, /max-w-full min-w-0 items-center gap-\[7px\]/);
    assert.equal(page.match(/className="w-\[128px\] min-w-0 /g)?.length, 2);
    assert.match(page, /Choosing Cash, Cashless\s+or Split leaves out unpaid Pay Later orders\./);
    assert.match(page, /hidden overflow-x-auto rounded-\[13px\] border border-\[#efefef\] md:block">\s+<table/);
});
