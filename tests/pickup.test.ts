import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import {
    isPickupBuzzMessage,
    PICKUP_BUZZ_MESSAGE,
    PICKUP_WORKER_SCOPE,
    PICKUP_WORKER_URL,
    pickupBuzzCue,
    pickupNotifyMessage,
    pickupLinkButtons,
    pickupNotifyState,
    pickupQueueView,
    pickupStatusView,
} from '../resources/js/lib/pickup.ts';

const base = {
    support: 'supported' as const,
    status: 'preparing' as const,
    serverAvailable: true,
    permission: 'default' as NotificationPermission,
    browserSubscribed: false,
    serverSubscribed: false,
};

test('notifications are on only when this browser and the server both hold the subscription', () => {
    assert.equal(pickupNotifyState(base), 'off');
    assert.equal(
        pickupNotifyState({
            ...base,
            permission: 'granted',
            browserSubscribed: true,
            serverSubscribed: true,
        }),
        'on',
    );
    /** The server removed a rejected endpoint: the customer can turn it on again. */
    assert.equal(
        pickupNotifyState({
            ...base,
            permission: 'granted',
            browserSubscribed: true,
        }),
        'off',
    );
    assert.equal(
        pickupNotifyState({ ...base, permission: 'denied' }),
        'blocked',
    );
});

test('unsupported, insecure and iPhone browsers keep the live page without a broken button', () => {
    assert.equal(
        pickupNotifyState({ ...base, support: 'install-first' }),
        'install-first',
    );
    assert.equal(
        pickupNotifyState({ ...base, support: 'insecure' }),
        'insecure',
    );
    assert.equal(
        pickupNotifyState({ ...base, support: 'unsupported' }),
        'unsupported',
    );
    assert.equal(
        pickupNotifyState({ ...base, serverAvailable: false }),
        'unavailable',
    );
    for (const state of [
        'install-first',
        'insecure',
        'unsupported',
        'unavailable',
        'blocked',
    ] as const) {
        assert.match(pickupNotifyMessage(state), /updates live/);
    }
    assert.equal(pickupNotifyState({ ...base, status: 'done' }), 'not-needed');
    assert.equal(
        pickupNotifyState({ ...base, status: 'unavailable' }),
        'not-needed',
    );
});

test('the pickup page shows Preparing, Ready and Done with the Take Out and overall queue positions', () => {
    assert.equal(pickupStatusView('preparing').title, 'Preparing');
    assert.equal(pickupStatusView('ready').tone, 'green');
    assert.equal(pickupStatusView('done').title, 'Picked up');
    assert.equal(pickupStatusView('unavailable').tone, 'neutral');
    assert.deepEqual(
        pickupQueueView({
            status: 'preparing',
            queue_position: 6,
            overall_position: 10,
        }),
        { takeOut: 6, overall: '#10 in the overall queue' },
    );
    /** Ready / Done orders are no longer waiting: no fake position. */
    assert.equal(
        pickupQueueView({
            status: 'ready',
            queue_position: 6,
            overall_position: 10,
        }),
        null,
    );
    assert.equal(
        pickupQueueView({
            status: 'preparing',
            queue_position: null,
            overall_position: null,
        }),
        null,
    );
});

test('customer link buttons show only configured http(s) links, never a placeholder', () => {
    assert.deepEqual(
        pickupLinkButtons({
            facebook: 'https://facebook.com/pongskilog',
            website: 'javascript:alert(1)',
            maps: 'https://maps.google.com/?q=Pongskilog',
        }).map((button) => button.key),
        ['facebook', 'maps'],
    );
    assert.deepEqual(
        pickupLinkButtons({ facebook: null, website: '', maps: null }),
        [],
    );
    const page = readFileSync(
        new URL('../resources/js/pages/pickup.tsx', import.meta.url),
        'utf8',
    );
    assert.match(page, /rel="noopener noreferrer"/);
    /** The receipt reuses the canonical customer receipt card. */
    assert.match(page, /<DigitalReceiptCard receipt=\{receipt\} \/>/);
    assert.match(page, /window\.print\(\)/);
});

test('the pickup worker is separate from the staff app worker and only shows this order’s Buzz', () => {
    const worker = readFileSync(
        new URL('../public/pickup-sw.js', import.meta.url),
        'utf8',
    );
    const staffWorker = readFileSync(
        new URL('../resources/js/service-worker/sw.ts', import.meta.url),
        'utf8',
    );
    const page = readFileSync(
        new URL('../resources/js/pages/pickup.tsx', import.meta.url),
        'utf8',
    );

    assert.equal(PICKUP_WORKER_URL, '/pickup-sw.js');
    assert.equal(PICKUP_WORKER_SCOPE, '/pickup/');
    assert.match(worker, /data\.type !== 'pickup\.ready'/);
    assert.match(
        worker,
        /PICKUP_PATH = \/\^\\\/pickup\\\/\[A-Za-z0-9_-\]\{43\}\$\//,
    );
    assert.match(worker, /vibrate:/);
    assert.doesNotMatch(
        worker,
        /addEventListener\('fetch'|caches\.|skipWaiting|clients\.claim/,
    );
    assert.doesNotMatch(staffWorker, /pickup/);
    /** Only the explicit button subscribes: the permission prompt and subscribe() live in its handler. */
    assert.equal(page.match(/pushManager\.subscribe\(/g)?.length, 1);
    assert.equal(page.match(/Notification\.requestPermission\(\)/g)?.length, 1);
    assert.match(
        page,
        /const enable = async \(\) => \{[\s\S]*Notification\.requestPermission\(\)[\s\S]*pushManager\.subscribe\(/,
    );
});

test('a Buzz is the push notification; only an open page in the foreground adds vibration and the optional sound', () => {
    assert.equal(PICKUP_BUZZ_MESSAGE, 'pickup.buzz');
    assert.equal(isPickupBuzzMessage({ type: 'pickup.buzz' }), true);
    assert.equal(isPickupBuzzMessage({ type: 'pickup.ready' }), false);
    assert.equal(isPickupBuzzMessage(null), false);
    assert.equal(isPickupBuzzMessage('pickup.buzz'), false);

    /** Locked / backgrounded: the OS notification (sound + vibration) is the Buzz; the page adds nothing. */
    assert.deepEqual(
        pickupBuzzCue({
            visible: false,
            soundUrl: '/audio/customer-screen-buzz.mp3',
        }),
        { vibrate: false, sound: null },
    );
    /** No MP3 installed: still a vibration, never a broken sound. */
    assert.deepEqual(pickupBuzzCue({ visible: true, soundUrl: null }), {
        vibrate: true,
        sound: null,
    });
    assert.deepEqual(
        pickupBuzzCue({
            visible: true,
            soundUrl: '/audio/customer-screen-buzz.mp3',
        }),
        { vibrate: true, sound: '/audio/customer-screen-buzz.mp3' },
    );
    assert.equal(
        pickupBuzzCue({ visible: true, soundUrl: 'https://evil.test/a.mp3' })
            .sound,
        null,
    );

    const worker = readFileSync(
        new URL('../public/pickup-sw.js', import.meta.url),
        'utf8',
    );
    const page = readFileSync(
        new URL('../resources/js/pages/pickup.tsx', import.meta.url),
        'utf8',
    );
    /** The push still always shows its notification; telling the open page is extra and cannot fail it. */
    assert.match(
        worker,
        /Promise\.all\(\[\s*self\.registration\.showNotification\([\s\S]*tellOpenPage\(target\)/,
    );
    assert.match(worker, /postMessage\(\{ type: 'pickup\.buzz' \}\)/);
    /** The page never creates audio without an installed file, and a blocked / failed play is swallowed. */
    assert.match(
        page,
        /if \(soundUrl === null \|\| typeof Audio === 'undefined'\) return;/,
    );
    assert.match(page, /void element\.play\(\)\.catch\(\(\) => undefined\)/);
    assert.match(page, /usePickupBuzzCue\(pickup\.buzz_sound_url\)/);
    assert.doesNotMatch(page, /customer-screen-buzz\.mp3/);
});
