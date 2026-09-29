import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    launchRestoreTarget,
    parseStoredRoute,
    restorableRoute,
} from '../resources/js/lib/pwa-launch.ts';
import {
    isOfflineWriteBlock,
    isServerWrite,
    OFFLINE_WRITE_MESSAGE,
    OfflineWriteBlockedError,
    writeDecision,
} from '../resources/js/lib/pwa-mutation-guard.ts';
import {
    DEFAULT_PUSH_TARGET,
    notificationContent,
    notificationTarget,
    parsePushPayload,
    PUSH_TARGETS,
} from '../resources/js/lib/pwa-notifications.ts';

const ORIGIN = 'https://pos.pongskilog.test';

test('every server write is blocked while not online, reads never are', () => {
    for (const method of ['post', 'PUT', 'patch', 'delete']) {
        assert.equal(isServerWrite(method), true, method);
        assert.equal(
            writeDecision({ method, url: '/pos/payments', serverWritesAllowed: false }),
            'block',
            method,
        );
        assert.equal(
            writeDecision({ method, url: '/pos/payments', serverWritesAllowed: true }),
            'send',
        );
    }
    assert.equal(writeDecision({ method: 'get', url: '/workspaces/kitchen', serverWritesAllowed: false }), 'send');
    assert.equal(writeDecision({ method: undefined, url: '/workspace', serverWritesAllowed: false }), 'send');
});

test('critical writes across the app are covered by the same rule', () => {
    const writes = [
        ['post', '/pos/payments'],
        ['post', '/pos/orders/0199/pay-later'],
        ['post', '/pos/orders/0199/settlements'],
        ['post', '/pos/transactions/0199/void'],
        ['patch', '/pos/transactions/0199'],
        ['post', '/store-sessions/open'],
        ['post', '/store-sessions/current/close'],
        ['post', '/store-sessions/current/expenses'],
        ['post', '/inventory/b/p/adjustments'],
        ['post', '/workspaces/operations/ingredients/0199/adjustments'],
        ['post', '/workspaces/operations/pamamalengke/0199/confirm'],
        ['post', '/store-sessions/current/giveaways'],
        ['patch', '/orders/0199/kitchen-status'],
        ['put', '/workspaces/staff/7'],
        ['put', '/workspaces/super-admin/staff/7/password'],
        ['put', '/workspaces/super-admin/access-control/roles/cashier'],
        ['put', '/branch-context/0199'],
        ['put', '/products/0199/branches/0198'],
        ['post', '/logout'],
    ];
    for (const [method, url] of writes) {
        assert.equal(writeDecision({ method, url, serverWritesAllowed: false }), 'block', url);
    }
});

test('writes the app sends by itself are blocked without the offline message', () => {
    for (const url of [
        '/pos/recipe-capacity',
        '/qr/0199/recipe-capacity',
        '/pos/orders/reservations',
        'https://pos.pongskilog.test/pos/orders/0199/receipt-share',
    ]) {
        assert.equal(writeDecision({ method: 'post', url, serverWritesAllowed: false }), 'block-silently', url);
    }
});

test('a blocked write is recognisable and carries the canonical message', () => {
    const error = new OfflineWriteBlockedError('/pos/payments');

    assert.equal(isOfflineWriteBlock(error), true);
    assert.equal(error.message, OFFLINE_WRITE_MESSAGE);
    assert.equal(OFFLINE_WRITE_MESSAGE, "You're offline. Reconnect to continue this operation.");
    assert.equal(isOfflineWriteBlock(new Error('Network error')), false);
    assert.equal(isOfflineWriteBlock(null), false);
});

test('lock-screen texts are fixed per type and never echo payload details', () => {
    const push = parsePushPayload(
        JSON.stringify({
            type: 'kitchen.new_order',
            tag: 'kitchen-new-order:0199',
            branch: 'Main',
            url: 'https://evil.example/phish',
            title: 'Juan Dela Cruz paid ₱1,250',
            body: '2x Tapsilog',
        }),
    );
    const content = notificationContent(push);

    assert.equal(content.title, 'PONGSKILOG · New Kitchen Order');
    assert.equal(content.body, 'A new order is waiting in Kitchen. · Main');
    assert.equal(content.tag, 'kitchen-new-order:0199');
    assert.equal(content.url, PUSH_TARGETS['kitchen.new_order']);
    assert.doesNotMatch(JSON.stringify(content), /Juan|1,250|Tapsilog|evil/);

    assert.equal(
        notificationContent(parsePushPayload('{"type":"order.ready","tag":"order-ready:1"}')).title,
        'PONGSKILOG · Order Ready',
    );
    assert.equal(
        notificationContent(parsePushPayload('{"type":"admin.alert","tag":"admin-alert:1"}')).body,
        'Open PONGSKILOG to review the alert.',
    );
});

test('malformed or unknown pushes still show a safe generic notification', () => {
    for (const payload of ['not json', '', null, '{"type":"order.refund"}', '[]']) {
        const content = notificationContent(parsePushPayload(payload));

        assert.equal(content.title, 'PONGSKILOG');
        assert.equal(content.url, DEFAULT_PUSH_TARGET);
    }
    const noisy = parsePushPayload(
        JSON.stringify({ type: 'order.ready', branch: `A\u0000B${'x'.repeat(80)}` }),
    );
    assert.equal(noisy.branch?.includes('\u0000'), false);
    assert.ok((noisy.branch?.length ?? 0) <= 40);
});

test('a notification tap opens only same-origin allowlisted pages', () => {
    assert.equal(notificationTarget('/workspaces/kitchen', ORIGIN), '/workspaces/kitchen');
    assert.equal(notificationTarget(`${ORIGIN}/workspaces/cashier?view=qr#x`, ORIGIN), '/workspaces/cashier');
    assert.equal(notificationTarget('https://evil.example/workspaces/kitchen', ORIGIN), DEFAULT_PUSH_TARGET);
    assert.equal(notificationTarget('//evil.example/workspaces/kitchen', ORIGIN), DEFAULT_PUSH_TARGET);
    assert.equal(notificationTarget('javascript:alert(1)', ORIGIN), DEFAULT_PUSH_TARGET);
    assert.equal(notificationTarget('/workspaces/super-admin/staff', ORIGIN), DEFAULT_PUSH_TARGET);
    assert.equal(notificationTarget(42, ORIGIN), DEFAULT_PUSH_TARGET);
});

test('launch recovery remembers only top-level screens, never signed links or other state', () => {
    assert.equal(restorableRoute(`${ORIGIN}/workspaces/kitchen`, ORIGIN), '/workspaces/kitchen');
    assert.equal(restorableRoute(`${ORIGIN}/workspaces/cashier?view=qr`, ORIGIN), '/workspaces/cashier?view=qr');
    assert.equal(restorableRoute(`${ORIGIN}/workspaces/reports?period=month`, ORIGIN), '/workspaces/reports');
    assert.equal(restorableRoute(`${ORIGIN}/receipt/0199?signature=abc&expires=1`, ORIGIN), null);
    assert.equal(restorableRoute(`${ORIGIN}/workspaces/transactions/0199`, ORIGIN), null);
    assert.equal(restorableRoute('https://evil.example/workspaces/kitchen', ORIGIN), null);
    assert.equal(parseStoredRoute('not json'), null);
});

test('a fresh installed-app launch through the start URL returns to the last screen, nothing else does', () => {
    const launch = {
        standalone: true,
        firstPageOfWindow: true,
        navigationType: 'navigate',
        redirectCount: 1,
        signedIn: true,
        stored: { path: '/workspaces/customer-display', savedAt: 1_000 },
        currentUrl: `${ORIGIN}/workspaces/cashier`,
        origin: ORIGIN,
        now: 2_000,
    };

    assert.equal(launchRestoreTarget(launch), '/workspaces/customer-display');
    assert.equal(launchRestoreTarget({ ...launch, standalone: false }), null);
    assert.equal(launchRestoreTarget({ ...launch, firstPageOfWindow: false }), null);
    assert.equal(launchRestoreTarget({ ...launch, navigationType: 'reload' }), null);
    assert.equal(launchRestoreTarget({ ...launch, redirectCount: 0 }), null, 'deep links and notification taps keep their page');
    assert.equal(launchRestoreTarget({ ...launch, signedIn: false }), null);
    assert.equal(launchRestoreTarget({ ...launch, now: 1_000 + 13 * 60 * 60 * 1000 }), null);
    assert.equal(
        launchRestoreTarget({ ...launch, stored: { path: '/workspaces/cashier', savedAt: 1_000 } }),
        null,
    );
});
