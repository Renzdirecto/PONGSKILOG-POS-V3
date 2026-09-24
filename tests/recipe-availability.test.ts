import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    activeSizeGroupNames,
    MODIFIER_ROLE_OPTIONS,
    modifierRoleFromValue,
    modifierRoleHelp,
    modifierRoleLabel,
} from '../resources/js/lib/modifier-roles.ts';
import {
    effectSummary,
    recipeNavNote,
    recipeSetupState,
} from '../resources/js/lib/operations.ts';
import {
    configurationProblem,
    optionAvailability,
    otherCartLines,
    quantityCap,
    recipeProductLabel,
    sizeAvailabilityLabel,
} from '../resources/js/lib/recipe-availability.ts';
import type { ConfigurationCapacity } from '../resources/js/lib/recipe-availability.ts';
import type { RecipeAvailability } from '../resources/js/types/catalog.ts';

/** Formatter line wrapping must not break text assertions, so whitespace is collapsed. */
const source = (path: string): string =>
    readFileSync(
        new URL(`../resources/js/${path}`, import.meta.url),
        'utf8',
    ).replace(/\s+/g, ' ');
const recipes = source('pages/operations/recipes.tsx');
const groupsPage = source('pages/catalog/modifiers.tsx');
const productEditor = source('components/product-editor-form.tsx');
const products = source('pages/catalog/products.tsx');
const dialog = source('components/pos-product-dialog.tsx');
const qrProduct = source('components/customer-qr-product.tsx');
const pos = source('components/cashier-pos.tsx');
const qr = source('components/customer-qr.tsx');
const hook = source('hooks/use-recipe-capacity.ts');

const lemonYakult: RecipeAvailability = {
    state: 'available',
    capacity: 20,
    sizes: [
        {
            key: 's',
            option_id: 's',
            name: 'Small',
            state: 'available',
            capacity: 20,
        },
        {
            key: 'm',
            option_id: 'm',
            name: 'Medium',
            state: 'available',
            capacity: 15,
        },
        {
            key: 'l',
            option_id: 'l',
            name: 'Large',
            state: 'out_of_stock',
            capacity: 0,
        },
    ],
};

test('groups offer exactly Size, Add-on / Modifier and Instructions over the existing roles', () => {
    assert.deepEqual(
        MODIFIER_ROLE_OPTIONS.map((option) => [option.label, option.role]),
        [
            ['Size', 'size'],
            ['Add-on / Modifier', null],
            ['Instructions', 'instruction'],
        ],
    );
    assert.equal(modifierRoleLabel(null), 'Add-on / Modifier');
    assert.equal(modifierRoleLabel(undefined), 'Add-on / Modifier');
    assert.equal(
        modifierRoleHelp('size'),
        'Defines the base recipe variant, such as Small, Medium, or Large.',
    );
    assert.equal(
        modifierRoleHelp(null),
        'Optional or required choices that may add price and ingredient usage.',
    );
    assert.equal(
        modifierRoleHelp('instruction'),
        'Preparation requests only. No ingredient or price effect.',
    );
    assert.equal(modifierRoleFromValue(''), null);
    assert.equal(modifierRoleFromValue('size'), 'size');

    for (const page of [groupsPage, productEditor]) {
        assert.doesNotMatch(page, /Standard options/);
        assert.match(page, /MODIFIER_ROLE_OPTIONS\.map/);
        assert.match(page, /modifierRoleHelp\(/);
    }
    assert.match(groupsPage, /form\.errors\.semantic_role/);
    assert.match(productEditor, /form\.errors\.modifier_group_ids/);
});

test('assigning two active size groups is flagged before the server rejects it', () => {
    const groups = [
        {
            id: 'a',
            name: 'Size',
            semantic_role: 'size' as const,
            is_active: true,
        },
        {
            id: 'b',
            name: 'Cup size',
            semantic_role: 'size' as const,
            is_active: true,
        },
        {
            id: 'c',
            name: 'Old size',
            semantic_role: 'size' as const,
            is_active: false,
        },
        { id: 'd', name: 'Add-ons', semantic_role: null, is_active: true },
    ];
    assert.deepEqual(activeSizeGroupNames(groups, ['a', 'c', 'd']), ['Size']);
    assert.deepEqual(activeSizeGroupNames(groups, ['a', 'b']), [
        'Size',
        'Cup size',
    ]);
    assert.match(productEditor, /Choose one Size group\./);
    assert.match(productEditor, /disabled=\{sizeConflict\.length > 1\}/);
});

test('the recipes page separates product stock, no recipe needed and a missing recipe', () => {
    const base = { size_conflict: null };
    assert.equal(
        recipeSetupState(
            { ...base, inventory_mode: 'product_stock' },
            { lines: null },
        ),
        'product_stock',
    );
    assert.equal(
        recipeSetupState(
            { ...base, inventory_mode: 'no_recipe_needed' },
            { lines: null },
        ),
        'no_recipe_needed',
    );
    assert.equal(
        recipeSetupState(
            { inventory_mode: 'recipe', size_conflict: ['Size', 'Cup size'] },
            undefined,
        ),
        'configuration_error',
    );
    assert.equal(
        recipeSetupState(
            { ...base, inventory_mode: 'recipe' },
            { lines: null },
        ),
        'recipe_missing',
    );
    assert.equal(
        recipeSetupState(
            { ...base, inventory_mode: 'recipe' },
            { lines: [{}] },
        ),
        'recipe_set',
    );

    assert.equal(
        recipeNavNote({ state: 'product_stock', sizes: [] }),
        'Uses Product stock',
    );
    assert.equal(
        recipeNavNote({ state: 'not_needed', sizes: [] }),
        'No recipe needed',
    );
    assert.equal(
        recipeNavNote({ state: 'missing', sizes: [] }),
        'Recipe not set',
    );
    assert.equal(
        recipeNavNote({
            state: 'partial',
            sizes: [{ lines: [{}] }, { lines: null }, { lines: [{}] }],
        }),
        '2 of 3 sizes set',
    );

    assert.match(recipes, /title="Uses Product stock"/);
    assert.match(
        recipes,
        /Product stock tracking must be turned off before using an Ingredient recipe to prevent double inventory deduction\./,
    );
    assert.match(recipes, /existing Product stock is kept/);
    assert.match(
        recipes,
        /href=\{`\$\{product\.settings_url\}&section=branch`\}/,
    );
    assert.match(recipes, /Open Product settings/);
    assert.match(recipes, /Use ingredient recipe/);
    assert.match(recipes, /<Plus className="size-4" \/> Set up recipe/);
    assert.match(products, /requested\.get\('section'\) === 'branch'/);
});

test('base recipes come only from sizes and add-on effects are a separate section without instructions', () => {
    assert.match(recipes, /Base recipe/);
    assert.match(
        recipes,
        /One base recipe per size from the product’s Size group\./,
    );
    assert.match(recipes, /No Size group, so one Regular recipe\./);
    assert.match(recipes, /aria-label="Base recipe sizes"/);
    assert.match(recipes, /Add-on \/ Modifier effects/);
    assert.match(recipes, /Configure ingredient effect/);
    assert.match(recipes, /Save as no effect/);
    assert.match(recipes, /operationsRoutes\.recipes\.effects\.update\.url/);
    assert.match(
        recipes,
        /never use ingredients or change the price, so they are not configured here/,
    );

    const ingredients = new Map([
        ['yakult', { name: 'Yakult', base_unit: 'pc' }],
        ['nata', { name: 'Nata', base_unit: 'g' }],
    ]);
    assert.equal(effectSummary(null, ingredients), 'No ingredient effect');
    assert.equal(
        effectSummary(
            [
                { ingredient_id: 'yakult', quantity: '1' },
                { ingredient_id: 'nata', quantity: '30' },
            ],
            ingredients,
        ),
        'Yakult +1 pc, Nata +30 g',
    );
});

test('size availability labels are truthful and never invent a count', () => {
    assert.equal(
        sizeAvailabilityLabel({ state: 'available', capacity: 20 }),
        '20 available',
    );
    assert.equal(
        sizeAvailabilityLabel({ state: 'out_of_stock', capacity: 0 }),
        'Out of stock',
    );
    assert.equal(
        sizeAvailabilityLabel({ state: 'recipe_required', capacity: null }),
        'Recipe required',
    );
    assert.equal(
        sizeAvailabilityLabel({ state: 'available', capacity: null }),
        'Available',
    );
    assert.equal(recipeProductLabel(lemonYakult), 'Available');
    assert.equal(
        recipeProductLabel({
            state: 'available',
            capacity: 15,
            sizes: [lemonYakult.sizes[1]],
        }),
        '15 available',
    );
    assert.equal(
        recipeProductLabel({ ...lemonYakult, state: 'out_of_stock' }),
        'Out of stock',
    );
    assert.equal(
        recipeProductLabel({
            state: 'configuration_error',
            capacity: null,
            sizes: [],
        }),
        'Needs setup',
    );
});

test('the selected configuration caps quantity and marks unfulfillable add-ons without blocking the base drink', () => {
    const capacity: ConfigurationCapacity = {
        limited: true,
        state: 'available',
        capacity: 2,
        options: { s: 5, m: 5, l: 0, extra_yakult: 0, nata: 5 },
    };
    assert.equal(quantityCap(capacity), 2);
    assert.equal(quantityCap(null), 999);
    assert.equal(
        quantityCap({
            limited: false,
            state: null,
            capacity: null,
            options: {},
        }),
        999,
    );
    assert.equal(configurationProblem(capacity, 2), null);
    assert.equal(
        configurationProblem(capacity, 3),
        'Only 2 can be made with this selection.',
    );
    assert.equal(
        configurationProblem(
            { ...capacity, capacity: 0, state: 'out_of_stock' },
            1,
        ),
        'Out of stock with this selection. Choose another size or remove an add-on.',
    );
    assert.equal(
        configurationProblem(
            { ...capacity, state: 'recipe_required', capacity: null },
            1,
        ),
        'This size needs a recipe before it can be sold.',
    );
    assert.equal(
        configurationProblem(
            { ...capacity, capacity: undefined, fits: false },
            4,
        ) !== null,
        true,
    );

    const product = { recipe: lemonYakult };
    assert.deepEqual(optionAvailability(product, capacity, 'm', true, false), {
        label: '5 available',
        unavailable: false,
    });
    assert.deepEqual(optionAvailability(product, capacity, 'l', true, false), {
        label: 'Out of stock',
        unavailable: true,
    });
    assert.deepEqual(
        optionAvailability(product, capacity, 'extra_yakult', false, false),
        { label: 'Unavailable', unavailable: true },
    );
    assert.deepEqual(
        optionAvailability(product, capacity, 'nata', false, false),
        { label: null, unavailable: false },
    );
    assert.deepEqual(
        optionAvailability(product, capacity, 'no_ice', false, true),
        { label: null, unavailable: false },
    );
    assert.deepEqual(
        optionAvailability({ recipe: null }, capacity, 'l', true, false),
        { label: null, unavailable: false },
    );
    assert.deepEqual(optionAvailability(product, null, 'm', true, false), {
        label: '15 available',
        unavailable: false,
    });
});

test('the rest of the cart shares ingredient stock with the item being customized', () => {
    const cart = [
        {
            key: 'a',
            product: { id: 'p1' },
            quantity: 2,
            modifiers: [{ group_id: 'g', option_id: 'm' }],
        },
        { key: 'b', product: { id: 'p2' }, quantity: 1, modifiers: [] },
    ];
    assert.deepEqual(otherCartLines(cart, 'a'), [
        { product_id: 'p2', quantity: 1, modifiers: [] },
    ]);
    assert.equal(otherCartLines(cart, undefined).length, 2);
});

test('pos and customer qr ask the server for capacity and enforce it in the dialog', () => {
    assert.match(hook, /DEBOUNCE_MS = 180/);
    assert.doesNotMatch(hook, /setInterval/);
    assert.match(pos, /capacityUrl=\{recipeCapacity\.url\(\)\}/);
    assert.match(
        pos,
        /otherLines=\{otherCartLines\(lines, editing\.line\?\.key\)\}/,
    );
    assert.match(qr, /capacityUrl=\{recipeCapacity\.url\(branch\.id\)\}/);
    assert.match(dialog, /Number\(quantity\) >= cap/);
    assert.match(dialog, /recipeProblem !== null/);
    assert.match(dialog, /disabled=\{ unavailable && !checked \}/);
    assert.match(qrProduct, /recipeProblem !== null/);
    assert.match(qrProduct, /availability\.unavailable/);
    assert.doesNotMatch(
        qrProduct,
        /available`/,
        'customers never see serving counts',
    );
});

test('recipe controls keep 44px touch targets on phones', () => {
    assert.match(
        recipes,
        /inline-flex h-11 shrink-0 items-center rounded-\[10px\]/,
    );
    assert.match(recipes, /flex min-h-14 min-w-0 flex-col items-start/);
    assert.match(
        recipes,
        /flex size-11 items-center justify-center rounded-\[10px\]/,
    );
    assert.match(dialog, /flex min-h-11 items-center gap-2 rounded-xl border/);
});
