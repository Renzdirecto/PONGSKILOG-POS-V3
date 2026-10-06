import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    DEFAULT_REPORT_PRESET,
    REPORT_TABS,
    appliedSelection,
    customRangeError,
    draftSelection,
    reportQuery,
    SESSION_RESULTS,
    sessionElapsedLabel,
    sessionTimeline,
    toggleFilterValue,
} from '../resources/js/lib/reports.ts';

import { nextCategorySelection } from '../resources/js/lib/owner-analytics.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const page = source('pages/workspaces/reports.tsx');
const sessions = source('components/report-store-sessions.tsx');
const components = source('components/owner-analytics.tsx');
const ownerShell = source('components/owner-workspace-shell.tsx');
const managementNavigation = source('lib/management-navigation.ts');
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
                date: 'session',
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

test('the period tabs open on the Store Session and keep the standalone range tabs', () => {
    assert.deepEqual(
        REPORT_TABS.map(([, label]) => label),
        ['Session', 'Daily', 'Weekly', 'Monthly', 'Yearly', 'Custom'],
    );
    assert.deepEqual(
        REPORT_TABS.map(([key]) => key),
        [
            'session',
            'today',
            'last_7_days',
            'last_30_days',
            'last_12_months',
            'custom',
        ],
    );
    /** Session is the default, so it is the preset left out of a shareable query. */
    assert.equal(DEFAULT_REPORT_PRESET, 'session');
    assert.deepEqual(reportQuery({}, { date: 'session' }), {});
    assert.deepEqual(reportQuery({ date: 'today' }, { date: 'session' }), {});
});

test('a Store Session period keeps its selected session; every other period clears it', () => {
    assert.deepEqual(
        reportQuery({ date: 'session', session: 'a' }, { date: 'session' }),
        { session: 'a' },
    );
    assert.deepEqual(reportQuery({ session: 'a' }, { date: 'today' }), {
        date: 'today',
    });
});

test('Store Session duration is formatted from server timestamps only', () => {
    assert.equal(sessionElapsedLabel(0), '0m');
    assert.equal(sessionElapsedLabel(47 * 60), '47m');
    assert.equal(sessionElapsedLabel(3 * 3600 + 22 * 60), '3h 22m');
    assert.equal(
        sessionTimeline(
            {
                status: 'open',
                opened_at: '2026-09-23T10:00:00+08:00',
                opened_at_time: '10:00 AM',
                closed_at_time: null,
                time_range: '10:00 AM – LIVE',
                duration_seconds: null,
            },
            new Date('2026-09-23T13:22:00+08:00').getTime(),
        ),
        'Opened 10:00 AM · Live 3h 22m',
    );
    assert.equal(
        sessionTimeline(
            {
                status: 'closed',
                opened_at: '2026-09-23T10:00:00+08:00',
                opened_at_time: '10:00 AM',
                closed_at_time: '8:00 PM',
                time_range: '10:00 AM – 8:00 PM',
                duration_seconds: 36_000,
            },
            Date.now(),
        ),
        '10:00 AM – 8:00 PM · 10h 0m',
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
            /Close Store<\/|Void<\/|Add expense|Stock correction|Settle/,
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
    /** The explanatory paragraph was removed; its one load-bearing sentence lives in the card hint. */
    assert.match(prose, /A category never filters Cash, Cashless or other money figures\./);
    assert.doesNotMatch(prose, /order items do not record the category at the time of sale/);
    assert.match(page, /Category: \$\{categoryScope\}/);
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
    assert.match(ownerShell, /case 'reports':\s+return reports\(\);/);
    assert.match(
        managementNavigation,
        /id: 'reports',\s+label: 'Reports',\s+shortLabel: 'Reports',\s+section: 'sales',\s+permission: 'reports\.view',/,
    );
    assert.match(
        managementNavigation,
        /component === 'workspaces\/reports'\) \{\s+return 'reports';/,
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

test('Period highlights come immediately after the KPI cards', () => {
    const kpis = page.indexOf('<KpiGrid analytics={analytics} comparison={comparison} />');
    const highlights = page.indexOf('title="Period highlights"');
    const categories = page.indexOf('title="Sales by category"');
    assert.ok(kpis > 0 && kpis < highlights && highlights < categories);
    assert.match(page, /Calculated from this period's figures/);
    assert.doesNotMatch(page, /Calculated from the figures above/);
});

test('Reports sits at the very bottom of the operational sidebar for every reports.view account', () => {
    const operational = layout.slice(
        layout.indexOf('const navigation = ['),
        layout.indexOf("].filter((item) => item.available)"),
    );
    const labels = [...operational.matchAll(/label: '([^']+)'/g)].map(
        (match) => match[1],
    );

    assert.deepEqual(labels, [
        'Dashboard',
        'POS',
        'QR Orders',
        'Kitchen',
        'History',
        'Display',
        'Reports',
    ]);
    /** The only gate is the permission: a business-wide viewer (Super Admin in the POS workspace) sees it too. */
    assert.match(
        operational,
        /label: 'Reports',\s+short: 'Reports',\s+icon: BarChart3,\s+available: auth\.permissions\.includes\('reports\.view'\),/,
    );
    assert.doesNotMatch(
        operational,
        /reports\.view'\)[\s\S]{0,80}!branchContext\.businessWide/,
    );
    /** Hidden without the permission: every item is filtered by its own `available`. */
    assert.match(
        layout,
        /\]\.filter\(\(item\) => item\.available\);/,
    );
    /** Both the desktop rail and the mobile dock render that one list, so neither can drop it. */
    assert.match(layout, /aria-label="Operational navigation"[\s\S]{0,400}navigation\.map\(/);
    assert.match(layout, /aria-label="Mobile operational navigation"[\s\S]{0,700}navigation\.map\(/);
});

test('the report sections follow the approved order after Order type', () => {
    const order = [
        'Period highlights',
        'Sales by category',
        'Payment method',
        'Order type',
        'Collections & drawer effects',
        'Product performance',
        'Top products',
        'Peak sales hours',
        'Sales trend',
        'Kitchen performance',
        'Store Sessions',
    ].map((title) => page.indexOf(`title="${title}"`));

    assert.ok(order.every((index) => index > 0), 'every section is rendered');
    assert.deepEqual(order, [...order].sort((a, b) => a - b));
});

test('Product performance has one category dropdown driving the existing category filter', () => {
    const card = page.slice(
        page.indexOf('title="Product performance"'),
        page.indexOf('title="Top products"'),
    );

    assert.match(card, /<span className=\{labelClass\}>Category<\/span>/);
    assert.match(card, /value=\{categoryChoice\}/);
    assert.match(card, /onChange=\{\(event\) =>\s*chooseCategory\(event\.target\.value\)/);
    assert.match(card, /<option value="">All categories<\/option>/);
    assert.match(card, /\{categoryOptions\.map\(\(category\) => \(/);
    /** The chips are gone: one quick filter, not two. */
    assert.doesNotMatch(card, /aria-pressed/);
    assert.doesNotMatch(page, /toggleCategoryChip/);
    /** It writes the same shareable `categories` filter the dialog and Sales by category use. */
    assert.match(
        page,
        /function chooseCategory\(value: string\) \{\s*if \(value !== MANY_CATEGORIES\) \{\s*pickCategories\(value === '' \? \[\] : \[value\]\);/,
    );
    assert.match(
        page,
        /const categoryChoice =\s*selectedCategories\.length === 0\s*\? ''\s*: selectedCategories\.length === 1\s*\? selectedCategories\[0\]\s*: MANY_CATEGORIES;/,
    );
    /** Sales by category still selects exactly its row's categories. */
    assert.deepEqual(nextCategorySelection({ ids: ['drinks'] }, []), [
        'drinks',
    ]);
    assert.deepEqual(
        nextCategorySelection({ ids: ['drinks'] }, ['drinks']),
        [],
    );
    assert.deepEqual(nextCategorySelection({ ids: ['food'] }, ['drinks']), [
        'food',
    ]);
});

test('Product performance leads with Rank, Product, Qty and Sales and scrolls the rest sideways', () => {
    const card = page.slice(
        page.indexOf('title="Product performance"'),
        page.indexOf('title="Top products"'),
    );
    const head = card.slice(card.indexOf('<thead'), card.indexOf('</thead>'));
    const columns = [...head.matchAll(/>\s*\n\s*([A-Za-z%][^<\n]*?)\s*\n\s*<\/th>/g)].map(
        (match) => match[1],
    );

    assert.deepEqual(columns, [
        'Rank',
        'Product',
        'Qty',
        'Sales',
        'Category',
        'Orders',
        '% of sales',
        'Avg price',
    ]);
    /** No card list fallback: the same table at 360, 390 and 430px. */
    assert.doesNotMatch(card, /min-\[1000px\]:hidden/);
    assert.doesNotMatch(card, /hidden[^"]*min-\[1000px\]:block/);
    assert.match(card, /max-h-\[468px\] overflow-auto min-\[1000px\]:max-h-\[680px\]/);
    /** The four leading columns fit a 360px screen; the rest are reached inside this box, never by the page. */
    assert.match(card, /min-w-\[700px\] table-fixed/);
    assert.match(card, /<div className="overflow-hidden rounded-\[13px\] border border-\[#efefef\]">/);
    assert.match(card, /<thead className="sticky top-0 z-10 bg-\[#fafafa\]">/);
    /** The product name wraps to two lines instead of truncating into something unreadable. */
    assert.match(
        card,
        /<span className="block line-clamp-2 leading-\[1\.3\] \[overflow-wrap:anywhere\]">\s*\{product\.name\}/,
    );
    assert.doesNotMatch(card, /<span className="block truncate">\s*\{product\.name\}/);
    assert.match(card, /<ProductSizes\s+sizes=\{\s*product\.sizes\s*\}/);
});

test('the Size breakdown is read-only snapshot data under the product name', () => {
    const component = page.slice(
        page.indexOf('function ProductSizes('),
        page.indexOf('function DrawerLadder('),
    );

    assert.match(component, /if \(sizes\.length === 0\) \{\s*return null;/);
    assert.match(component, /\{size\.name\} \{size\.quantity\.toLocaleString\('en-PH'\)\}/);
});

test('Collections & drawer effects is Opening, Expenses then Closing, with the detail one tap away', () => {
    const card = page.slice(
        page.indexOf('title="Collections & drawer effects"'),
        page.indexOf('title="Product performance"'),
    );
    const rows = [
        card.indexOf('label="Opening Cash"'),
        card.indexOf('<ExpenseBreakdown'),
        card.indexOf("label={closingLabel('Cash')}"),
        card.indexOf('<CalculationDetails'),
    ];

    assert.ok(rows.every((index) => index > 0), 'every row is rendered');
    assert.deepEqual(rows, [...rows].sort((a, b) => a - b));
    assert.match(card, /<div className="grid grid-cols-2 gap-2\.5">/);
    assert.ok(card.includes('label="Opening Cashless"'));
    assert.ok(card.includes("label={closingLabel('Cashless')}"));
    /** Closing Cash is green, Closing Cashless is blue. */
    assert.match(card, /text-\[#15803D\]">\s*\{reconciliation\.closing\.cash/);
    assert.match(card, /text-\[#1D4ED8\]">\s*\{reconciliation\.closing\.cashless/);
    /** The tall per-channel ladder is no longer always visible on the page. */
    assert.doesNotMatch(card, /<DrawerLadder/);

    const expenses = page.slice(
        page.indexOf('function ExpenseBreakdown('),
        page.indexOf('function CalculationDetails('),
    );
    assert.match(expenses, /<details className=\{disclosureClass\}>/);
    assert.match(expenses, /View details\s*\n\s*<DisclosureChevron \/>/);
    /** Real expense descriptions only, never an invented item name. */
    assert.match(expenses, /\{item\.description\}/);

    const calculation = page.slice(
        page.indexOf('function CalculationDetails('),
        page.indexOf('function CategoryDot('),
    );
    assert.match(calculation, /<details className=\{disclosureClass\}>/);
    assert.match(calculation, /How was this calculated\?/);
    assert.match(calculation, /<DrawerLadder[\s\S]{0,200}channel="cashless"/);

    /** The backend ladder itself is untouched. */
    const ladder = page.slice(
        page.indexOf('function DrawerLadder('),
        page.indexOf('const disclosureClass ='),
    );
    assert.deepEqual(
        [...ladder.matchAll(/label: '([^']+)', sign/g)].map(
            (match) => match[1],
        ),
        ['Opening', 'Collections', 'Expenses', 'Corrections', 'Void reversals'],
    );
    assert.match(ladder, /reconciliation\.expected\[channel\]/);
    assert.match(ladder, /reconciliation\.closing\[channel\]/);
});

test('Period highlights report the kitchen average and the Store Session duration', () => {
    assert.doesNotMatch(page, /Cashless share/);
    assert.match(sessions, /label=\{live \? 'Open for' : 'Duration'\}/);
    assert.match(sessions, /sessionTimeline\(session, now\)/);
    assert.match(sessions, /sessionElapsedLabel\(/);
});
