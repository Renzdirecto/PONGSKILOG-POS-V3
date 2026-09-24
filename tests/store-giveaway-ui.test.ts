import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    isBlank,
    isMoneyInput,
    isPositiveMoneyInput,
    isWholeQuantity,
    requiredGroupOutline,
    requiredOutline,
} from '../resources/js/lib/required-field.ts';
import {
    GIVEAWAY_REASONS,
    giveawayError,
    giveawaySelection,
} from '../resources/js/lib/store-giveaway.ts';
import { sessionActivity } from '../resources/js/lib/store-inventory-adjustment.ts';

const source = (path: string) =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');
const form = source('components/store-giveaway-form.tsx');
const dialog = source('components/store-session-details-dialog.tsx');
const productDialog = source('components/pos-product-dialog.tsx');
const expense = dialog;
const adjustment = source('components/store-inventory-adjustment-form.tsx');
const openStore = source('components/cashier-store.tsx');
const recipes = source('pages/operations/recipes.tsx');

test('required controls are red until valid, then neutral gray; optional ones never use it', () => {
    assert.match(requiredOutline(true), /border-\[#b91c1c\]/);
    assert.match(
        requiredOutline(true, true),
        /border-\[1\.5px\] border-\[#b91c1c\]/,
    );
    assert.equal(requiredOutline(false), 'border border-[#e5e5e5]');
    assert.equal(requiredOutline(false, true), 'border-[1.5px] border-[#111]');
    assert.equal(requiredGroupOutline(true), 'border-[#b91c1c]');
    assert.equal(requiredGroupOutline(false), 'border-neutral-200');

    assert.equal(isMoneyInput(''), false);
    assert.equal(isMoneyInput('0'), true);
    assert.equal(isMoneyInput('12.345'), false);
    assert.equal(isPositiveMoneyInput('0.00'), false);
    assert.equal(isPositiveMoneyInput('25.50'), true);
    assert.equal(isWholeQuantity(''), false);
    assert.equal(isWholeQuantity('0'), false);
    assert.equal(isWholeQuantity('1.5'), false);
    assert.equal(isWholeQuantity('3'), true);
    assert.equal(isBlank('  '), true);
});

test('Store forms mark only their required fields and describe the requirement in text', () => {
    assert.match(
        openStore,
        /aria-invalid=\{\s*!!errors\.opening_cash_amount \|\|\s*cashMissing\s*\}/,
    );
    assert.match(
        openStore,
        /aria-invalid=\{\s*!!errors\.opening_cashless_amount \|\|\s*cashlessMissing\s*\}/,
    );
    assert.match(openStore, /Required · enter 0 if there is no opening/);
    assert.match(expense, /aria-invalid=\{descriptionMissing\}/);
    assert.match(expense, /aria-invalid=\{amountMissing\}/);
    assert.match(expense, /requiredGroupOutline\(productMissing\)/);
    assert.match(expense, /aria-invalid=\{quantityMissing\}/);
    /** Note and receipt stay optional: never red. */
    assert.doesNotMatch(expense, /id="expense-note"[\s\S]{0,200}aria-invalid/);
    assert.match(adjustment, /requiredGroupOutline\(reason === null\)/);
    assert.match(adjustment, /requiredGroupOutline\(product === null\)/);
    assert.match(
        adjustment,
        /const noteMissing = noteRequired && isBlank\(note\);/,
    );
});

test('a Recipe Size without a recipe is red with "Recipe required"; configured sizes are neutral', () => {
    assert.match(
        recipes,
        /requiredOutline\(!item\.lines\?\.length, item\.key === size\.key\)/,
    );
    assert.match(recipes, /: 'Recipe required'\}/);
    assert.match(recipes, /title=\{`Recipe required for \$\{sizeLabel\}`\}/);
    /** Optional Add-on effects stay neutral when there is no effect. */
    assert.match(
        recipes,
        /addOn\.lines \? 'text-\[#444\]' : 'text-\[#8a8a8a\]'/,
    );
});

test('Record giveaway is a third Store Session action next to expenses and adjustments', () => {
    assert.match(dialog, /Add expense \/ purchase/);
    assert.match(dialog, /Adjust inventory/);
    assert.match(dialog, /<Gift className="size-4" \/> Record giveaway/);
    assert.match(
        dialog,
        /view === 'giveaway' && session && \(\s*<StoreGiveawayForm/,
    );
    assert.match(
        dialog,
        /view === 'giveaway-detail' && session && selectedGiveaway/,
    );
});

test('the giveaway flow reuses the canonical Product customization and posts no money', () => {
    assert.match(form, /<PosProductDialog[\s\S]*purpose="giveaway"/);
    assert.match(form, /capacityUrl=\{recipeCapacity\.url\(\)\}/);
    assert.match(form, /\.\.\.giveawayCatalog\(\)/);
    assert.match(
        form,
        /product_id: line\.product\.id,\s*quantity: line\.quantity,\s*modifiers: line\.modifiers,/,
    );
    assert.doesNotMatch(form, /amount|payment_source|cash_received/);
    assert.match(form, /Revenue ₱0 · no payment · no expense/);
    assert.match(form, /Review giveaway/);
    assert.match(form, /Confirm giveaway/);
    assert.match(form, /requiredGroupOutline\(reason === null\)/);
    assert.match(form, /Required · choose the product, then its size/);
    assert.match(form, /Reverse giveaway/);
    assert.match(form, /Exactly the stock recorded above is restored, once\./);

    assert.match(productDialog, /purpose\?: 'cart' \| 'giveaway'/);
    assert.match(productDialog, /'Use this item'/);
    assert.match(
        productDialog,
        /\{!giveaway && \(\s*<div className="space-y-2">\s*<Label\s+htmlFor="pos-notes"/,
    );
    /** A required Group (such as Size) is red until chosen, in POS and giveaway alike. */
    assert.match(productDialog, /requiredGroupOutline\(missing\)/);
    assert.match(productDialog, /Required · choose/);
});

test('giveaway selections name Size, Add-ons and Instructions from the Product Groups', () => {
    const groups = [
        {
            id: 'size',
            name: 'Size',
            semantic_role: 'size',
            options: [{ id: 'm', name: 'Medium' }],
        },
        {
            id: 'add',
            name: 'Add-ons',
            semantic_role: null,
            options: [
                { id: 'nata', name: 'Nata' },
                { id: 'ey', name: 'Extra Yakult' },
            ],
        },
        {
            id: 'ins',
            name: 'Instructions',
            semantic_role: 'instruction',
            options: [{ id: 'ice', name: 'No ice' }],
        },
    ];
    assert.deepEqual(
        giveawaySelection(groups, [
            { group_id: 'size', option_id: 'm' },
            { group_id: 'add', option_id: 'nata' },
            { group_id: 'add', option_id: 'ey' },
            { group_id: 'ins', option_id: 'ice' },
            { group_id: 'gone', option_id: 'x' },
        ]),
        {
            size: 'Medium',
            addOns: ['Nata', 'Extra Yakult'],
            instructions: ['No ice'],
        },
    );
    assert.deepEqual(
        GIVEAWAY_REASONS.map((reason) => reason.value),
        [
            'complimentary',
            'service_recovery',
            'promotion',
            'staff_meal',
            'other',
        ],
    );
});

test('giveaway errors surface the server message and never guess', () => {
    assert.match(
        giveawayError(new Error('offline')),
        /Reconnect and try again/,
    );
    assert.match(
        giveawayError({ response: { status: 409 } }),
        /already used with different details/,
    );
    assert.equal(
        giveawayError({
            response: {
                status: 422,
                data: {
                    errors: {
                        quantity: [
                            'Not enough ingredient stock for this giveaway.',
                        ],
                    },
                },
            },
        }),
        'Not enough ingredient stock for this giveaway.',
    );
    assert.match(
        giveawayError(
            {
                response: {
                    status: 422,
                    data: '{"errors":{"giveaway":["already been reversed"]}}',
                },
            },
            'reversal',
        ),
        /already been reversed/,
    );
});

test('giveaways join the session history newest-first', () => {
    const activity = sessionActivity(
        [{ id: 'e1', created_at: '2026-09-24T10:00:00+08:00' }],
        [{ id: 'a1', created_at: '2026-09-24T11:00:00+08:00' }],
        [{ id: 'g1', created_at: '2026-09-24T12:00:00+08:00' }],
    );
    assert.deepEqual(
        activity.map((entry) => `${entry.kind}:${entry.item.id}`),
        ['giveaway:g1', 'adjustment:a1', 'expense:e1'],
    );
});
