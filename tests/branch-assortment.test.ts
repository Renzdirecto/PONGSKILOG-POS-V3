import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    copyResultNeedsReview,
    copySummary,
    matchesSearch,
    newAtDestination,
    type CopyPreviewRow,
} from '../resources/js/lib/branch-assortment.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

const row = (
    id: string,
    configuredHere: boolean,
    tracks = false,
): CopyPreviewRow => ({
    product_id: id,
    name: `Product ${id}`,
    category_name: 'Drinks',
    is_active: true,
    source: {
        configured: true,
        sold: true,
        price_override: null,
        effective_price: '50.00',
        tracks_inventory: tracks,
        low_stock_threshold: null,
    },
    destination: {
        configured: configuredHere,
        sold: true,
        effective_price: '50.00',
    },
});

const rows = [row('a', false, true), row('b', true), row('c', false)];

test('the safe default selection is products not configured at the destination', () => {
    assert.deepEqual(newAtDestination(rows), ['a', 'c']);
});

test('existing destination settings are kept unless overwriting is chosen', () => {
    const selected = new Set(['a', 'b']);

    assert.deepEqual(copySummary(rows, selected, false), {
        copy: 1,
        overwrite: 0,
        skip: 1,
        tracked: 1,
    });
    assert.deepEqual(copySummary(rows, selected, true), {
        copy: 1,
        overwrite: 1,
        skip: 0,
        tracked: 1,
    });
});

test('bulk pickers search by product or category name', () => {
    assert.equal(matchesSearch(row('a', false), 'product a'), true);
    assert.equal(matchesSearch(row('a', false), 'DRINK'), true);
    assert.equal(matchesSearch(row('a', false), 'rice'), false);
    assert.equal(matchesSearch(row('a', false), '  '), true);
});

test('branch product management edits only branch settings and never shows definition tools', () => {
    const products = source('pages/catalog/products.tsx');
    const catalog = source('components/catalog-ui.tsx');
    const editor = source('components/product-editor-form.tsx');

    assert.match(products, /const branchOnly = !scope\.can_edit_definitions;/);
    /** A selected Branch lists its assortment; only a business-wide manager also reaches the shared definition. */
    assert.match(products, /assortment \? \(\s*<BranchProductCard/);
    assert.match(
        products,
        /onEditProduct=\{\s*branchOnly\s*\? undefined\s*: \(\) => openEditor\(product, 'product'\)\s*\}/,
    );
    assert.match(
        catalog,
        /action=\{definitions \? <CatalogQuickActions \/> : undefined\}/,
    );
    assert.match(editor, /updateBranchProduct\(\{/);
});

test('branch settings hide branch creation and lock identity fields', () => {
    const settings = source('pages/branches/index.tsx');

    assert.match(settings, /scope\.can_create \? \(/);
    assert.match(settings, /editing !== null && !scope\.can_edit_identity/);
});

test('operations setup is configured per selected branch, never as one shared set', () => {
    for (const page of ['plans', 'ingredients', 'recipes']) {
        const text = source(`pages/operations/${page}.tsx`);
        assert.match(text, /const canEdit = operations\.can_configure;/);
        assert.doesNotMatch(text, /can_manage_definitions/);
    }
});

test('a copy result with skipped products stays on screen with each reason', () => {
    const base = {
        copied: 2,
        overwritten: 0,
        kept: 0,
        conflicts: [],
        operations_skipped: [],
    };

    assert.equal(copyResultNeedsReview(undefined), false);
    assert.equal(copyResultNeedsReview(base), false);
    assert.equal(
        copyResultNeedsReview({
            ...base,
            conflicts: [
                { product_id: 'c', name: 'Coke', reason: 'Recipe at TEST' },
            ],
        }),
        true,
    );
    assert.equal(
        copyResultNeedsReview({
            ...base,
            operations_skipped: ['Lemon uses another unit'],
        }),
        true,
    );

    const dialog = source('components/branch-assortment-dialogs.tsx');
    assert.match(
        dialog,
        /if \(copyResultNeedsReview\(outcome\)\) \{\s+setResult\(outcome\);/,
    );
    assert.match(dialog, /Skipped · \{conflict\.name\}/);
    assert.match(dialog, /role="status"/);
});
