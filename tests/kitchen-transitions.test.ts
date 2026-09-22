import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    KitchenTransitionStore,
    projectKitchenBoard,
} from '../resources/js/lib/kitchen-transitions.ts';
import type { KitchenTransitionResult } from '../resources/js/lib/kitchen-transitions.ts';
import { filterKitchenTickets } from '../resources/js/lib/kitchen.ts';
import type {
    KitchenBoardData,
    KitchenTicket,
} from '../resources/js/types/kitchen.ts';

function fixture(): KitchenBoardData {
    return {
        is_open: true,
        counts: { all: 5, kitchen: 5, preparing: 0, ready: 0, done: 0 },
        tickets: Array.from({ length: 5 }, (_, i): KitchenTicket => ({
            id: String(i),
            number: String(1001 + i),
            customer: null,
            table: null,
            order_type: 'dine_in',
            status: 'kitchen',
            version: 7,
            items: [],
            placed_at: '',
        })),
    };
}

function deferred() {
    let resolve!: (result: KitchenTransitionResult) => void;
    let reject!: (error: Error) => void;
    const promise = new Promise<KitchenTransitionResult>((yes, no) => {
        resolve = yes;
        reject = no;
    });
    return { promise, resolve, reject };
}

test('five independent requests start immediately, same-order duplicates are guarded and one failure rolls back only itself', async () => {
    const board = fixture();
    const store = new KitchenTransitionStore();
    const requests = board.tickets.map(deferred);
    const errors: unknown[] = [];
    let started = 0;
    let refreshes = 0;
    const tasks = board.tickets.map((ticket, i) =>
        store.run(
            ticket,
            'preparing',
            () => {
                started += 1;
                return requests[i].promise;
            },
            assert.fail,
            (error) => errors.push(error),
            () => {
                refreshes += 1;
            },
        ),
    );
    assert.equal(started, 5);
    assert.equal(
        Object.values(store.snapshot()).filter((entry) => entry.pending).length,
        5,
    );
    await store.run(
        board.tickets[0],
        'ready',
        () => {
            assert.fail('duplicate submitted');
        },
        assert.fail,
        assert.fail,
        assert.fail,
    );
    const optimistic = projectKitchenBoard(board, store.snapshot());
    assert.deepEqual(optimistic.counts, {
        all: 5,
        kitchen: 0,
        preparing: 5,
        ready: 0,
        done: 0,
    });
    assert.equal(
        filterKitchenTickets(optimistic.tickets, 'kitchen', '').length,
        0,
    );
    assert.equal(board.tickets[0].status, 'kitchen');
    for (const i of [4, 1, 3, 0]) {
        requests[i].resolve({
            order_id: String(i),
            from: 'kitchen',
            to: 'preparing',
            changed: true,
            version: 8,
        });
    }
    requests[2].reject(new Error('Store validation rejected C'));
    await Promise.all(tasks);
    assert.equal(errors.length, 1);
    assert.equal(refreshes, 5);
    assert.deepEqual(
        projectKitchenBoard(board, store.snapshot()).tickets.map(
            (ticket) => ticket.status,
        ),
        ['preparing', 'preparing', 'kitchen', 'preparing', 'preparing'],
    );
    assert.equal(
        Object.values(store.snapshot()).some((entry) => entry.pending),
        false,
    );
    const authoritative = projectKitchenBoard(board, store.snapshot());
    store.reconcile(authoritative);
    assert.deepEqual(store.snapshot(), {});
});

test('Ready sound waits for a changed server result and stays silent for failure and duplicate targets', async () => {
    const ticket = fixture().tickets[0];
    const store = new KitchenTransitionStore();
    const request = deferred();
    let sounds = 0;
    const sound = () => {
        sounds += 1;
    };
    const task = store.run(
        ticket,
        'ready',
        () => request.promise,
        sound,
        assert.fail,
        () => {},
    );
    assert.equal(sounds, 0);
    request.resolve({
        order_id: ticket.id,
        from: 'kitchen',
        to: 'ready',
        changed: true,
        version: 8,
    });
    await task;
    assert.equal(sounds, 1);
    await store.run(
        { ...ticket, status: 'ready', version: 8 },
        'ready',
        async () => ({
            order_id: ticket.id,
            from: 'ready',
            to: 'ready',
            changed: false,
            version: 8,
        }),
        sound,
        assert.fail,
        () => {},
    );
    await store.run(
        { ...ticket, status: 'ready', version: 8 },
        'preparing',
        async () => {
            throw new Error('offline');
        },
        sound,
        () => {},
        () => {},
    );
    assert.equal(sounds, 1);
    assert.equal(
        projectKitchenBoard(fixture(), store.snapshot()).tickets[0].status,
        'ready',
    );
});

test('older refreshes cannot undo confirmations and newer server versions override overlays', async () => {
    const board = fixture();
    const store = new KitchenTransitionStore();
    await store.run(
        board.tickets[0],
        'done',
        async () => ({
            order_id: '0',
            from: 'kitchen',
            to: 'done',
            changed: true,
            version: 8,
        }),
        assert.fail,
        assert.fail,
        () => {},
    );
    store.reconcile(board);
    assert.equal(projectKitchenBoard(board, store.snapshot()).counts.all, 4);
    const newer = {
        ...board,
        tickets: board.tickets.map((ticket) =>
            ticket.id === '0'
                ? { ...ticket, status: 'ready' as const, version: 9 }
                : ticket,
        ),
    };
    assert.equal(
        projectKitchenBoard(newer, store.snapshot()).tickets[0].status,
        'ready',
    );
    store.reconcile(newer);
    assert.deepEqual(store.snapshot(), {});
});
