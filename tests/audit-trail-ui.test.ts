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
const realtimeHook = readFileSync(
    new URL(
        '../resources/js/hooks/use-audit-realtime-refresh.ts',
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
    assert.match(realtimeHook, /\['\.audit\.recorded'\]/);
    assert.match(realtimeHook, /usePoll\(3000, \{ only \}\)/);
});

test('void orders updates search automatically and shares live refresh', () => {
    const voidOrdersPage = readFileSync(
        new URL(
            '../resources/js/pages/super-admin/void-orders.tsx',
            import.meta.url,
        ),
        'utf8',
    );

    assert.match(voidOrdersPage, /useAuditRealtimeRefresh/);
    assert.match(
        voidOrdersPage,
        /window\.setTimeout\(\(\) => apply\(\{ search \}\), 350\)/,
    );
    assert.match(voidOrdersPage, /preserveState: true/);
    assert.match(voidOrdersPage, /New voids\s+appear live/);
});

test('audit and void detail dialogs use a wide landscape layout', () => {
    const voidOrdersPage = readFileSync(
        new URL(
            '../resources/js/pages/super-admin/void-orders.tsx',
            import.meta.url,
        ),
        'utf8',
    );
    const wideDialogClasses =
        /max-h-\[82dvh\].*sm:max-w-5xl xl:max-w-6xl/;

    assert.match(page, wideDialogClasses);
    assert.match(voidOrdersPage, wideDialogClasses);
});
