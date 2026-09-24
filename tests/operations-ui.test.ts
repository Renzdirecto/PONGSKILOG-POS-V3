import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    boughtLines,
    checklistStorageKey,
    clearChecklist,
    divideProfit,
    emptyChecklist,
    estimateLineCents,
    formatDelta,
    formatPeso,
    formatQuantity,
    lineTotalCents,
    parseMoney,
    parseQuantity,
    parseSignedQuantity,
    planQuery,
    readChecklist,
    stockPercent,
    unitLabel,
    writeChecklist,
} from '../resources/js/lib/operations.ts';
import type { ChecklistItem } from '../resources/js/lib/operations.ts';

/** Formatter line wrapping must not break text assertions, so whitespace is collapsed. */
const source = (path: string): string =>
    readFileSync(
        new URL(`../resources/js/${path}`, import.meta.url),
        'utf8',
    ).replace(/\s+/g, ' ');
const ui = source('components/operations-ui.tsx');
const shell = source('components/owner-workspace-shell.tsx');
const layout = source('layouts/workspace-layout.tsx');
const app = source('app.tsx');
const plans = source('pages/operations/plans.tsx');
const overview = source('pages/operations/overview.tsx');
const ingredients = source('pages/operations/ingredients.tsx');
const recipes = source('pages/operations/recipes.tsx');
const stock = source('pages/operations/stock.tsx');
const market = source('pages/operations/pamamalengke.tsx');
const purchases = source('pages/operations/purchases.tsx');
const inventory = source('pages/inventory/index.tsx');

test('quantities are exact ten-thousandths, never floats', () => {
    assert.equal(parseQuantity('29.5'), 295_000);
    assert.equal(parseQuantity('0.125'), 1_250);
    assert.equal(parseQuantity('12.5'), 125_000);
    assert.equal(parseQuantity('1.12345'), null);
    assert.equal(parseQuantity('-1'), null);
    assert.equal(parseSignedQuantity('-0.25'), -2_500);
    assert.equal(formatQuantity('29.5', 'pc'), '29.5 pcs');
    assert.equal(formatQuantity('1', 'pack'), '1 pack');
    assert.equal(formatQuantity('970', 'ml'), '970 ml');
    assert.equal(formatQuantity('-0.25', 'pc'), '-0.25 pc');
    assert.equal(formatQuantity('2', 'pc'), '2 pcs');
    assert.equal(formatDelta('-0.5'), '−0.5');
    assert.equal(formatDelta('30'), '+30');
    assert.equal(unitLabel('box', 2), 'boxes');
});

test('money is integer centavos and recipe previews round like the server', () => {
    assert.equal(parseMoney('55.5'), 5_550);
    assert.equal(parseMoney('1.234'), null);
    assert.equal(formatPeso(123_456), '₱1,234.56');
    assert.equal(formatPeso(-500), '−₱5.00');
    assert.equal(formatPeso(500, true), '₱5');
    assert.equal(
        estimateLineCents('0.5', { size: '1', cost_cents: 1_000 }),
        500,
    );
    assert.equal(
        estimateLineCents('1', { size: '5', cost_cents: 5_500 }),
        1_100,
    );
    assert.equal(
        estimateLineCents('30', { size: '1000', cost_cents: 15_000 }),
        450,
    );
    assert.equal(
        estimateLineCents('1', { size: '3', cost_cents: 4_700 }),
        1_567,
    );
    assert.equal(estimateLineCents('1', { size: '5', cost_cents: null }), null);
    assert.equal(estimateLineCents('1', null), null);
    assert.equal(lineTotalCents('2.5', '45.10'), 11_275);
    assert.equal(lineTotalCents('3', ''), null);
});

test('the profit divider is a display-only calculator with no hidden centavos', () => {
    assert.deepEqual(divideProfit(10_000, 3), {
        each: 3_333,
        remainder: 1,
        shares: 3,
    });
    assert.deepEqual(divideProfit(10_000, 0), {
        each: 10_000,
        remainder: 0,
        shares: 1,
    });
    assert.deepEqual(divideProfit(10_000, 99), {
        each: 500,
        remainder: 0,
        shares: 20,
    });
    assert.deepEqual(divideProfit(-900, 2), {
        each: -450,
        remainder: 0,
        shares: 2,
    });

    const summary = ui.slice(
        ui.indexOf('export function SummaryDialog'),
        ui.indexOf('function SummaryList'),
    );
    assert.equal(
        summary.includes('router.'),
        false,
        'the summary must not write anything',
    );
    assert.match(
        summary,
        /does not record an expense, payment or owner withdrawal/,
    );
});

test('cash view is never labelled profit and the profit view is marked estimated', () => {
    assert.match(ui, /Cash after purchases/);
    assert.match(
        ui,
        /Purchased stock may still remain in inventory, so this is not true profit\./,
    );
    assert.match(
        ui,
        /Profit view\s*(\{' '\})?\s*<Chip tone="gold">\s*Estimated\s*<\/Chip>/,
    );
    assert.match(ui, /Estimated gross profit/);
    assert.match(ui, /Store-wide expenses are not allocated to a plan/);
    assert.match(ui, /never treated as ₱0 cost/);
    assert.match(ui, /value: 'market', label: 'Pamamalengke'/);
    assert.match(ui, /value: 'profit', label: 'Sales & profit'/);
});

test('the checklist is a guarded per-device convenience', () => {
    const original = globalThis.window;
    const store = new Map<string, string>();
    Object.assign(globalThis, {
        window: {
            localStorage: {
                getItem: (key: string) => store.get(key) ?? null,
                setItem: (key: string, value: string) =>
                    void store.set(key, value),
                removeItem: (key: string) => void store.delete(key),
            },
        },
    });
    const key = checklistStorageKey('branch', 'plan');
    writeChecklist(key, {
        ...emptyChecklist(),
        paymentSource: 'cashless',
        idempotencyKey: 'k',
    });
    assert.equal(readChecklist(key).paymentSource, 'cashless');
    assert.equal(readChecklist(key).idempotencyKey, 'k');
    clearChecklist(key);
    assert.deepEqual(readChecklist(key), emptyChecklist());

    Object.assign(globalThis, {
        window: {
            localStorage: {
                getItem: () => {
                    throw new Error('blocked');
                },
                setItem: () => {
                    throw new Error('blocked');
                },
                removeItem: () => {
                    throw new Error('blocked');
                },
            },
        },
    });
    assert.deepEqual(readChecklist(key), emptyChecklist());
    assert.doesNotThrow(() => writeChecklist(key, emptyChecklist()));
    assert.doesNotThrow(() => clearChecklist(key));
    Object.assign(globalThis, { window: original });
});

test('only bought, available lines with a quantity reach Confirm; actual cost stays separate from the estimate', () => {
    const items: ChecklistItem[] = [
        {
            key: 'i:lemon',
            type: 'ingredient',
            ingredientId: 'lemon',
            entryId: null,
            name: 'Lemon',
            unit: 'pc',
            planned: '1',
            estimatedUnitCents: 1_000,
        },
        {
            key: 'm:ice',
            type: 'manual',
            ingredientId: null,
            entryId: 'ice',
            name: 'Ice',
            unit: 'bag',
            planned: '2',
            estimatedUnitCents: null,
        },
        {
            key: 'm:gas',
            type: 'manual',
            ingredientId: null,
            entryId: 'gas',
            name: 'LPG',
            unit: 'tank',
            planned: '1',
            estimatedUnitCents: 92_500,
        },
    ];
    const lines = boughtLines(items, {
        ...emptyChecklist(),
        lines: {
            'i:lemon': {
                bought: true,
                unavailable: false,
                quantity: '3',
                unitCost: '9.50',
                note: '',
                open: false,
            },
            'm:ice': {
                bought: true,
                unavailable: false,
                quantity: '2',
                unitCost: '',
                note: '',
                open: false,
            },
            'm:gas': {
                bought: false,
                unavailable: true,
                quantity: '1',
                unitCost: '925.00',
                note: '',
                open: false,
            },
        },
    });

    assert.deepEqual(
        lines.map((line) => [line.key, line.totalCents, line.estimateCents]),
        [
            ['i:lemon', 2_850, 1_000],
            ['m:ice', null, null],
        ],
    );
});

test('operations is a real sidebar section of the existing Owner shell, with Sales kept separate', () => {
    assert.match(
        shell,
        /label: 'Sales',[\s\S]*label: 'Transactions'[\s\S]*label: 'Reports'/,
    );
    for (const [key, label] of [
        ['plans', 'Pamalengke Plans'],
        ['overview', 'Overview'],
        ['ingredients', 'Ingredients'],
        ['recipes', 'Recipes'],
        ['stock', 'Ingredient Stock'],
        ['pamamalengke', 'Pamamalengke'],
        ['purchases', 'Purchases'],
    ]) {
        assert.match(shell, new RegExp(`\\['${key}', '${label}'`));
    }
    assert.match(shell, /label: 'Operations',/);
    assert.match(shell, /operationsRoutes\[key\]\(planQuery\)/);
    assert.match(layout, /page\.component\.startsWith\('operations\/'\)/);
    assert.match(app, /case name\.startsWith\('operations\/'\):/);
});

test('the active plan lives in the URL and every page shares one shell', () => {
    assert.deepEqual(planQuery('plan-1'), { query: { plan: 'plan-1' } });
    assert.deepEqual(planQuery(null), {});
    assert.match(
        ui,
        /export function operationsHref\(\s*page: OperationsPageKey,\s*planId\?: string \| null,?\s*\)/,
    );
    assert.match(
        ui,
        /aria-label=\{`Active plan: \$\{active\.name\}\. Switch plan`\}/,
    );
    assert.match(ui, /Manage plans/);
    for (const page of [
        plans,
        overview,
        ingredients,
        recipes,
        stock,
        market,
        purchases,
    ]) {
        assert.match(page, /<OperationsShell/);
    }
    assert.equal(
        /localStorage/.test(ui),
        false,
        'the plan scope is never kept in local storage',
    );
});

test('plans, recipes and ingredients tell the truth about missing data', () => {
    assert.match(plans, /A plan does not hold stock of its own\./);
    assert.match(plans, /No plans yet/);
    assert.match(plans, /moves it for future sales only/);
    assert.match(recipes, /Recipe not set for \$\{sizeLabel\}/);
    assert.match(recipes, /No recipe needed/);
    assert.match(recipes, /Only existing Catalog products appear here\./);
    assert.match(recipes, /recipeNavNote\(item\)/);
    assert.match(recipes, /Needs every ingredient cost/);
    assert.match(ingredients, /shared with another plan, so/);
    assert.match(stock, /Wastage can't exceed current stock/);
    assert.match(stock, /counted − system stock/);
    assert.match(overview, /never counted as ₱0/);
});

test('the shopping checklist blocks Confirm truthfully and keeps a stable retry key', () => {
    assert.match(market, /value: 'shop', label:[\s\S]*Shopping checklist/);
    assert.match(market, /Add manual item/);
    assert.match(market, /Mark at least one item as bought\./);
    assert.match(market, /The Store at \{branchName\} is closed\./);
    assert.match(market, /disabled=\{busy \|\| !hasOpenSession\}/);
    assert.match(market, /checklist\.idempotencyKey \?\? createClientUuid\(\)/);
    assert.match(market, /Not available/);
    assert.match(market, /Actual total/);
    assert.match(
        market,
        /Estimated \{formatPeso\(estimate\)\} for these items/,
    );
    assert.match(market, /Paid from/);
    assert.match(purchases, /Pamamalengke keeps no ledger of its own\./);
});

test('catalog inventory filters products and ingredients from one data source', () => {
    assert.match(inventory, /label="Inventory type"/);
    assert.match(inventory, /value: 'ingredients'/);
    assert.match(
        inventory,
        /same canonical Branch balance as Operations › Ingredient Stock/,
    );
});

test('important actions keep 44px touch targets and never overflow the page', () => {
    assert.match(ui, /export const opsButtonClass =\s*'inline-flex min-h-11/);
    assert.match(ui, /export const opsPrimaryClass =\s*'inline-flex min-h-11/);
    assert.match(ui, /export const opsInputClass =\s*'h-11 w-full min-w-0/);
    assert.match(
        ui,
        /owner-hide-scrollbar -mx-0\.5 flex gap-1\.5 overflow-x-auto/,
    );
    assert.match(market, /role="checkbox"[\s\S]*size-11/);
    assert.match(
        ui,
        /top-auto bottom-0 flex max-h-\[92dvh\]/,
        'dialogs open as bottom sheets on phones',
    );
});
