import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
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
    assert.match(products, /branchOnly \? \(\s*<BranchProductCard/);
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

test('shared operations definitions are read-only without business-wide operations', () => {
    for (const page of ['plans', 'ingredients', 'recipes']) {
        assert.match(
            source(`pages/operations/${page}.tsx`),
            /const canEdit = operations\.can_manage_definitions;/,
        );
    }
});
