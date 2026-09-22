import assert from 'node:assert/strict';
import { test } from 'node:test';
import { restoredOwnerViewMode } from '../resources/js/lib/owner-view-preference.ts';

test('Owner view preference restores only the supported list value', () => {
    assert.equal(restoredOwnerViewMode('list'), 'list');
    assert.equal(restoredOwnerViewMode('tile'), 'tile');
    assert.equal(restoredOwnerViewMode(null), 'tile');
});
