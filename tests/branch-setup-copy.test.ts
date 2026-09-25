import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    operationsCopySummary,
    type CopyOperationsDetails,
} from '../resources/js/lib/branch-assortment.ts';
import {
    NEVER_COPIED,
    nothingToCopy,
    setupCopyLines,
    type SetupCopyResult,
} from '../resources/js/lib/operations-setup-copy.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

/** Lemon Yakult (recipe, Drinks) and Iced Tea (recipe, Drinks) share Lemon; Coke is direct resale with Product stock. */
const details: CopyOperationsDetails = {
    products: {
        lemon: {
            mode: 'recipe',
            plan_id: 'drinks',
            recipes: 3,
            effects: 2,
            ingredient_ids: ['i-lemon', 'i-yakult'],
            destination_configured: false,
        },
        tea: {
            mode: 'recipe',
            plan_id: 'drinks',
            recipes: 1,
            effects: 0,
            ingredient_ids: ['i-lemon', 'i-tea'],
            destination_configured: true,
        },
        coke: {
            mode: 'product_stock',
            plan_id: null,
            recipes: 0,
            effects: 0,
            ingredient_ids: [],
            destination_configured: false,
        },
        water: {
            mode: 'direct',
            plan_id: 'drinks',
            recipes: 0,
            effects: 0,
            ingredient_ids: [],
            destination_configured: false,
        },
    },
    plans: { drinks: { name: 'Drinks', exists: false } },
    ingredients: {
        'i-lemon': { name: 'Lemon', exists: false, conflict: false },
        'i-yakult': { name: 'Yakult', exists: true, conflict: false },
        'i-tea': { name: 'Tea', exists: true, conflict: true },
    },
};

test('the product copy review deduplicates shared plans and ingredients across the selection', () => {
    const summary = operationsCopySummary(
        details,
        new Set(['lemon', 'tea', 'coke', 'water']),
        false,
    );

    assert.equal(summary.plans, 1);
    assert.equal(summary.ingredients, 1);
    assert.equal(summary.ingredientsKept, 1);
    assert.equal(summary.conflicts, 1);
    /** Tea is already configured at the destination, so it is kept; Coke brings no Operations setup. */
    assert.equal(summary.recipes, 3);
    assert.equal(summary.effects, 2);
    assert.equal(summary.direct, 1);
    assert.equal(summary.configuredKept, 1);
});

test('replace counts existing destination setup as overwritten, never as kept', () => {
    const summary = operationsCopySummary(
        details,
        new Set(['lemon', 'tea']),
        true,
    );

    assert.equal(summary.recipes, 4);
    assert.equal(summary.configuredKept, 0);
    assert.equal(summary.ingredients, 2);
    assert.equal(summary.ingredientsKept, 0);
    assert.equal(summary.conflicts, 1);
});

const result = (overrides: Partial<SetupCopyResult> = {}): SetupCopyResult => ({
    products: 2,
    not_in_destination: 0,
    plans: { new: 2, existing: 0, replaced: 0 },
    ingredients: { new: 8, existing: 1, replaced: 0, conflicts: 0 },
    plan_products: { assigned: 2, moved: 0, kept: 0 },
    recipes: {
        products: 2,
        recipes: 7,
        effects: 3,
        direct: 0,
        replaced: 0,
        kept: 1,
    },
    skipped: [],
    ...overrides,
});

test('the operations copy review lists what is copied and what is kept', () => {
    assert.deepEqual(setupCopyLines(result()), {
        copies: [
            '2 Plans',
            '8 Ingredients',
            '7 Recipes',
            '3 Add-on effects',
            '2 products placed in a Plan',
        ],
        kept: [
            '1 Ingredient already here',
            '1 product already configured here',
        ],
    });
    assert.equal(nothingToCopy(result()), false);
    assert.equal(
        nothingToCopy(
            result({
                plans: { new: 0, existing: 2, replaced: 0 },
                ingredients: { new: 0, existing: 9, replaced: 0, conflicts: 0 },
                plan_products: { assigned: 0, moved: 0, kept: 2 },
                recipes: {
                    products: 0,
                    recipes: 0,
                    effects: 0,
                    direct: 0,
                    replaced: 0,
                    kept: 2,
                },
            }),
        ),
        true,
    );
});

test('stock and history are never offered as something to copy', () => {
    assert.deepEqual(NEVER_COPIED.slice(0, 2), [
        'Product stock',
        'Ingredient stock',
    ]);
    for (const path of [
        'components/branch-assortment-dialogs.tsx',
        'components/operations-setup-copy-dialog.tsx',
    ]) {
        const text = source(path);
        assert.match(text, /Will NOT copy/);
        assert.match(text, /NEVER_COPIED\.map/);
        assert.match(
            text,
            /Replacing configuration affects future sales only\.\s*Historical sales remain unchanged\./,
        );
    }
});

test('the product copy offers operations setup only with operations access and reviews before confirming', () => {
    const dialogs = source('components/branch-assortment-dialogs.tsx');
    const products = source('pages/catalog/products.tsx');

    assert.match(dialogs, /Copy Operations setup for selected products/);
    assert.match(dialogs, /canCopyOperations && preview\.operations &&/);
    assert.match(dialogs, /copy_operations: withOperations,/);
    assert.match(
        dialogs,
        /const \[withOperations, setWithOperations\] = useState\(false\);/,
    );
    assert.match(products, /canCopyOperations=\{scope\.can_copy_operations\}/);
});

test('the operations copy keeps destination setup by default and writes only after a reviewed dry run', () => {
    const dialog = source('components/operations-setup-copy-dialog.tsx');

    assert.match(dialog, /const \[replace, setReplace\] = useState\(false\);/);
    assert.match(dialog, /Keep \$\{destination\?\.code\}'s setup \(skip\)/);
    assert.match(dialog, /setupCopyPreview\.url\(/);
    assert.match(dialog, /review \? \(\s*<button[\s\S]*Confirm copy/);
    assert.match(
        dialog,
        /!operations\.branch \|\|\s*!operations\.can_configure \|\|\s*operations\.copy_sources\.length === 0/,
    );
});

test('remove from a branch is a separate confirmed action from marking a product unavailable', () => {
    const products = source('pages/catalog/products.tsx');
    const dialogs = source('components/branch-assortment-dialogs.tsx');

    assert.match(products, /'Mark unavailable'/);
    assert.match(products, /Remove from \{config\.code\}/);
    assert.match(products, /onRemove=\{\(\) => setRemoving\(product\)\}/);
    assert.match(dialogs, /router\.delete\(removeFromAssortment\.url\(\), \{/);
    assert.match(
        dialogs,
        /stock balance and movement history are kept\s+\(never zeroed\)/,
    );
    assert.match(dialogs, /To pause it instead, mark it unavailable\./);
});

test('a new branch shows a truthful empty assortment and operations setup with ways to fill it', () => {
    const products = source('pages/catalog/products.tsx');
    const plans = source('pages/operations/plans.tsx');
    const ingredients = source('pages/operations/ingredients.tsx');
    const recipes = source('pages/operations/recipes.tsx');
    const shell = source('components/operations-ui.tsx');

    assert.match(products, /No products in \{assortment\.code\} yet\./);
    assert.match(products, /Products — \{assortment\.code\}/);
    assert.match(plans, /No Pamalengke Plans yet\./);
    assert.match(plans, /Create manually/);
    assert.match(ingredients, /No Ingredients configured for this Branch\./);
    assert.match(recipes, /No Recipes configured for this Branch\./);
    for (const page of [plans, ingredients, recipes]) {
        assert.match(page, /<OperationsSetupCopyButton/);
    }
    /** Operations headings name the Branch; All Branches shows the picker state, never a shared setup. */
    assert.match(
        shell,
        /const heading = branch \? `\$\{title\} · \$\{branch\.code\}` : title;/,
    );
    assert.match(
        shell,
        /needsBranch \? \(\s*<EmptyState\s*title="Choose a Branch"/,
    );
});

test('a new product joins only the branches explicitly selected in the editor', () => {
    const editor = source('components/product-editor-form.tsx');

    assert.match(
        editor,
        /branch_configs: data\.branch_configs\s*\.filter\(\(config\) => config\.in_assortment\)/,
    );
    assert.match(editor, /label=\{`Sell at \$\{branch\.code\}`\}/);
    assert.match(editor, /\(product === null && branches\.length === 1\)/);
});
