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

    assert.match(hook, /'reports',\s+\['\.reports\.changed'\]/);
    assert.match(hook, /router\.reload\(\{ only: onlyRef\.current, onFinish \}\)/);
    assert.match(hook, /usePoll\(\s+30_000,/);
    assert.match(
        dashboard,
        /useReportsRealtimeRefresh\(\s+\['analytics', 'report', \.\.\.LIVE_PROPS\],\s+report\.scope\?\.id \?\? null,\s+\)/,
    );
    assert.match(
        reports,
        /useReportsRealtimeRefresh\(\s+\['report', 'analytics', 'kitchenNow'\],\s+report\.scope\?\.id \?\? null,\s+\)/,
    );
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
