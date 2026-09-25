import assert from 'node:assert/strict';
import { test } from 'node:test';
import { readFileSync } from 'node:fs';
import {
    createBranchEventGuard,
    createQrVersionRecovery,
    createRealtimeRefresh,
    createReportsEventGuard,
    getAuditRealtimeFallbackAction,
} from '../resources/js/lib/realtime-refresh.ts';
import { shouldRefetchCatalogAfterConnectionChange } from '../resources/js/lib/pos-catalog-realtime.ts';

const jsSource = (path: string): string =>
    readFileSync(new URL(`../resources/js/${path}`, import.meta.url), 'utf8');

test('reports signals refresh every branch for All Branches and only the selected branch otherwise', () => {
    const allBranches = createReportsEventGuard(null);
    const main = createReportsEventGuard('main');

    assert.equal(allBranches({ event_id: 'a', branch_id: 'main' }), true);
    assert.equal(allBranches({ event_id: 'b', branch_id: 'qave' }), true);
    assert.equal(allBranches({ event_id: 'a', branch_id: 'main' }), false);
    assert.equal(allBranches({ event_id: 'c' }), false);
    assert.equal(main({ event_id: 'd', branch_id: 'qave' }), false);
    assert.equal(main({ event_id: 'e', branch_id: 'main' }), true);
});

test('owner dashboard and reports reload their report props from the reports channel', () => {
    const hook = jsSource('hooks/use-reports-realtime-refresh.ts');
    const dashboard = jsSource('pages/workspaces/owner-dashboard.tsx');
    const reports = jsSource('pages/workspaces/reports.tsx');

    assert.match(hook, /channel,\s+\['\.reports\.changed'\]/);
    assert.match(hook, /reportsChannelFor\(branchContext\.businessWide, branchId\)/);
    assert.match(hook, /router\.reload\(\{\s+only: onlyRef\.current,\s+onCancelToken:/);
    assert.match(hook, /REPORTS_FALLBACK_POLL_MS = 30_000/);
    assert.match(hook, /if \(!shouldPoll\) \{\s+return;\s+\}/);
    assert.match(hook, /router\.on\('start'[\s\S]+refresh\.hold\(\)/);
    assert.match(hook, /router\.on\('finish'[\s\S]+refresh\.release\(\)/);
    assert.doesNotMatch(hook, /usePoll/);
    assert.doesNotMatch(dashboard, /usePoll/);
    assert.match(
        dashboard,
        /useReportsRealtimeRefresh\(\s+\['analytics', 'report', \.\.\.LIVE_PROPS\],\s+report\.scope\?\.id \?\? null,\s+\)/,
    );
    assert.match(
        reports,
        /useReportsRealtimeRefresh\(\s+\['report', 'analytics', 'kitchenNow'\],\s+report\.scope\?\.id \?\? null,\s+\)/,
    );
});

test('a report refresh waits for the page own visit and cancels a stale reload in flight', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let requests = 0;
    let cancelled = 0;
    let finish = () => {};
    const refresh = createRealtimeRefresh((done) => {
        requests += 1;
        finish = done;

        return () => {
            cancelled += 1;
            done();
        };
    }, 100);

    refresh.schedule();
    refresh.hold();
    t.mock.timers.tick(500);
    assert.equal(requests, 0);
    refresh.release();
    t.mock.timers.tick(0);
    assert.equal(requests, 1);

    refresh.hold();
    assert.equal(cancelled, 1);
    refresh.schedule();
    t.mock.timers.tick(500);
    assert.equal(requests, 1);
    refresh.release();
    t.mock.timers.tick(0);
    assert.equal(requests, 2);
    finish();

    refresh.hold();
    refresh.release();
    t.mock.timers.tick(500);
    assert.equal(requests, 2);
    refresh.dispose();
});

test('audit realtime stops fallback polling while Echo is connected', () => {
    const action = getAuditRealtimeFallbackAction('connected', 'connected');

    assert.deepEqual(action, {
        shouldPoll: false,
        shouldRefresh: false,
    });
});

test('audit realtime starts fallback polling while Echo is unavailable', () => {
    for (const status of [
        'connecting',
        'reconnecting',
        'disconnected',
        'failed',
    ]) {
        const action = getAuditRealtimeFallbackAction('connected', status);

        assert.deepEqual(action, {
            shouldPoll: true,
            shouldRefresh: false,
        });
    }
});

test('audit realtime refreshes once and stops polling after reconnecting', () => {
    const action = getAuditRealtimeFallbackAction(
        'reconnecting',
        'connected',
    );

    assert.deepEqual(action, {
        shouldPoll: false,
        shouldRefresh: true,
    });
});

test('submitted order tracking recovers once when background refresh finds new assets', () => {
    let reloads = 0;
    let prevented = 0;
    const recover = createQrVersionRecovery('submitted-order', () => reloads++);
    const event = {
        detail: { versionChange: true },
        preventDefault: () => prevented++,
    };

    recover(event);
    recover(event);

    assert.equal(reloads, 1);
    assert.equal(prevented, 2);
});

test('asset recovery preserves unsent carts and leaves ordinary redirects alone', () => {
    let reloads = 0;
    let prevented = 0;
    const event = {
        detail: { versionChange: true },
        preventDefault: () => prevented++,
    };

    createQrVersionRecovery(undefined, () => reloads++)(event);
    createQrVersionRecovery(
        'submitted-order',
        () => reloads++,
    )({
        ...event,
        detail: { versionChange: false },
    });

    assert.equal(reloads, 0);
    assert.equal(prevented, 0);
});

for (const delay of [35, 160, 200]) {
    test(`refresh coalesces bursts at ${delay}ms and queues one refresh while in flight`, (t) => {
        t.mock.timers.enable({ apis: ['setTimeout'] });
        let requests = 0;
        let finish = () => {};
        const refresh = createRealtimeRefresh((done) => {
            requests += 1;
            finish = done;
        }, delay);
        for (let i = 0; i < 10; i++) {
            refresh.schedule();
        }
        t.mock.timers.tick(delay - 1);
        assert.equal(requests, 0);
        t.mock.timers.tick(1);
        assert.equal(requests, 1);
        for (let i = 0; i < 10; i++) {
            refresh.schedule();
        }
        t.mock.timers.tick(500);
        assert.equal(requests, 1);
        finish();
        t.mock.timers.tick(0);
        assert.equal(requests, 2);
        refresh.schedule();
        refresh.dispose();
        finish();
        t.mock.timers.tick(500);
        assert.equal(requests, 2);
        refresh.activate();
        refresh.schedule();
        t.mock.timers.tick(delay);
        assert.equal(requests, 3);
        refresh.dispose();
    });
}

test('branch guards ignore duplicate events and foreign branches; reconnect still refreshes authoritatively', (t) => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const accept = createBranchEventGuard('main');
    assert.equal(accept({ branch_id: 'other', event_id: '1' }), false);
    assert.equal(accept({ branch_id: 'main', event_id: '1' }), true);
    assert.equal(accept({ branch_id: 'main', event_id: '1' }), false);
    let requests = 0;
    const refresh = createRealtimeRefresh((done) => {
        requests += 1;
        done();
    }, 35);
    if (
        shouldRefetchCatalogAfterConnectionChange(
            'reconnecting',
            'connected',
            true,
        )
    ) {
        refresh.schedule(0);
    }
    t.mock.timers.tick(0);
    assert.equal(requests, 1);
    assert.equal(
        shouldRefetchCatalogAfterConnectionChange(
            'connecting',
            'connected',
            false,
        ),
        false,
    );
    refresh.dispose();
});

test('a page can ignore report reasons that never change it, and operations ignores kitchen status', () => {
    const operations = createReportsEventGuard('main', ['kitchen.status_changed']);

    assert.equal(operations({ event_id: 'k', branch_id: 'main', reason: 'kitchen.status_changed' }), false);
    assert.equal(operations({ event_id: 'o', branch_id: 'main', reason: 'order.committed' }), true);
    assert.match(
        jsSource('components/operations-ui.tsx'),
        /OPERATIONS_IGNORED_REASONS = \['kitchen\.status_changed'\]/,
    );
});

test('background reloads send a revoked session to the workspace instead of a raw error dialog', () => {
    for (const hook of ['use-branch-realtime-refresh', 'use-audit-realtime-refresh', 'use-pos-qr-realtime']) {
        const source = jsSource(`hooks/${hook}.ts`);
        assert.match(source, /onHttpException: handleRevalidationException/, hook);
        assert.match(source, /onNetworkError: \(\) => false/, hook);
    }
});

test('the POS QR state is not refetched right after the server rendered it, only after a reconnect', () => {
    const hook = jsSource('hooks/use-pos-qr-realtime.ts');

    assert.match(hook, /shouldRefetchCatalogAfterConnectionChange\(/);
    assert.doesNotMatch(hook, /if \(connection === 'connected'\) refresh\.schedule\(0\)/);
});

test('business transactions listen to reports signals and poll only for accounts that cannot hear them', () => {
    const page = jsSource('pages/workspaces/transaction-history.tsx');

    assert.match(page, /canHearReports \? \(\s*<BusinessHistoryRealtime/);
    assert.match(page, /useReportsRealtimeRefresh\(HISTORY_PROPS, branchId\)/);
});

test('the audit register refreshes only its entries on each new audit record', () => {
    assert.match(
        jsSource('pages/super-admin/audit-trail.tsx'),
        /const realtimeProps = useMemo\(\(\) => \['logs'\], \[\]\)/,
    );
});
