import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    createBranchEventGuard,
    createRealtimeRefresh,
} from '../resources/js/lib/realtime-refresh.ts';
import { shouldRefetchCatalogAfterConnectionChange } from '../resources/js/lib/pos-catalog-realtime.ts';

for (const delay of [35, 160]) {
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
