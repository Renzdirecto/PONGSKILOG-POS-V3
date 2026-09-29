import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { summaryErrors } from '../resources/js/lib/required-field.ts';

const source = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

test('the summary lists only errors that are not already shown beside their field', () => {
    const errors = {
        name: 'The name field is required.',
        modifier_group_ids: 'Tapsilog can have only one active Size group.',
        'modifier_group_ids.0': 'The selected group is invalid.',
        'inline_groups.0.name': 'The group title is required.',
        'inline_groups.0.options.0.price_delta':
            'Instruction options cannot change the price.',
    };

    assert.deepEqual(
        summaryErrors(errors, [
            'name',
            'modifier_group_ids',
            /^inline_groups\.\d+\.name$/,
        ]),
        {
            'modifier_group_ids.0': 'The selected group is invalid.',
            'inline_groups.0.options.0.price_delta':
                'Instruction options cannot change the price.',
        },
    );
    assert.deepEqual(summaryErrors(errors), errors);
    assert.deepEqual(summaryErrors({}, ['name']), {});
});

test('the product editor renders the group error once and keeps zero groups a valid state', () => {
    const editor = source('components/product-editor-form.tsx');

    assert.equal(
        editor.match(/\{form\.errors\.modifier_group_ids\}/g)?.length,
        1,
    );
    assert.match(editor, /PRODUCT_INLINE_ERRORS[^;]*'modifier_group_ids'/s);
    assert.match(editor, /inline=\{PRODUCT_INLINE_ERRORS\}/);
    assert.match(
        editor,
        /modifier_group_ids: product\?\.modifier_group_ids \?\? \[\]/,
    );
    assert.match(editor, /Groups are\s+optional/);
    assert.doesNotMatch(editor, /form\.errors\.image &&/);
});

test('every catalog summary excludes the errors its form renders inline', () => {
    for (const [path, inlineKey] of [
        ['pages/catalog/categories.tsx', "'icon_key'"],
        ['pages/catalog/modifiers.tsx', "'semantic_role'"],
        ['components/inventory-adjustment-dialog.tsx', "'quantity_delta'"],
    ] as const) {
        const page = source(path);
        assert.match(
            page,
            /<FormErrors\s+errors=\{form\.errors\}\s+inline=\{/,
            path,
        );
        assert.ok(page.includes(inlineKey), path);
    }
    assert.match(
        source('components/catalog-ui.tsx'),
        /summaryErrors\(allErrors, inline\)/,
    );
});

test('staff and copy dialogs mark missing required values red with readable text', () => {
    const addStaff = source('pages/super-admin/staff.tsx');
    for (const field of ['employee_id', 'name', 'email', 'password', 'role']) {
        assert.ok(
            addStaff.includes(`!!form.errors.${field} || missing.${field}`),
            field,
        );
    }
    assert.match(addStaff, /Required · choose at least one Branch\./);
    assert.match(addStaff, /aria-invalid:border-\[#b91c1c\]/);

    const editStaff = source('components/staff-account-dialogs.tsx');
    assert.match(editStaff, /!!form\.errors\.name \|\| missing\.name/);
    assert.match(editStaff, /requiresBranch &&\s+!shared &&/);

    const setupCopy = source('components/operations-setup-copy-dialog.tsx');
    assert.match(setupCopy, /Required · choose the Branch to copy from\./);
    assert.match(setupCopy, /Required · choose at least one part to copy\./);
    assert.match(setupCopy, /requiredOutline\(\s*sections\.length === 0/);

    const assortment = source('components/branch-assortment-dialogs.tsx');
    assert.match(assortment, /Required · choose the Branch to copy from\./);
    assert.match(
        assortment,
        /sells no products yet, so there is nothing to copy/,
    );
    assert.match(assortment, /No products match/);
});
