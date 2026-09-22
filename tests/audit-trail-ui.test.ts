import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';

const page = readFileSync(
    new URL(
        '../resources/js/pages/super-admin/audit-trail.tsx',
        import.meta.url,
    ),
    'utf8',
);

test('audit trail presents readable activity instead of raw JSON panels', () => {
    assert.match(page, /Activity feed/);
    assert.match(page, /Recorded changes/);
    assert.match(page, /Human-readable before and after values/);
    assert.match(page, /Context metadata/);
    assert.doesNotMatch(page, /<pre/);
});

test('audit trail keeps live refresh and debounced server filters', () => {
    assert.match(page, /useAuditRealtimeRefresh/);
    assert.match(
        page,
        /window\.setTimeout\(\(\) => apply\(\{ search \}\), 350\)/,
    );
    assert.match(page, /preserveState: true/);
    assert.match(page, /Live monitoring/);
});
