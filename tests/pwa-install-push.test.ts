import assert from 'node:assert/strict';
import { test } from 'node:test';
import {
    detectInstallPlatform,
    installGuidance,
    installState,
} from '../resources/js/lib/pwa-install.ts';
import {
    matchesApplicationServerKey,
    pushState,
    pushSupport,
    subscriptionBody,
    urlBase64ToUint8Array,
} from '../resources/js/lib/pwa-push.ts';

const UA = {
    iphone: 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1',
    ipadDesktop:
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Safari/605.1.15',
    android:
        'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Mobile Safari/537.36',
    windowsChrome:
        'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
    macChrome:
        'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36',
};

test('platform detection only picks instructions: iPadOS desktop user agents are recognised by touch', () => {
    assert.equal(detectInstallPlatform(UA.iphone, 5), 'ios');
    assert.equal(detectInstallPlatform(UA.ipadDesktop, 5), 'ios');
    assert.equal(detectInstallPlatform(UA.ipadDesktop, 0), 'macos-safari');
    assert.equal(detectInstallPlatform(UA.android, 5), 'android');
    assert.equal(detectInstallPlatform(UA.windowsChrome, 0), 'desktop');
    assert.equal(detectInstallPlatform(UA.macChrome, 0), 'desktop');
});

test('an installed app hides the install call to action', () => {
    assert.equal(
        installState({ standalone: true, promptAvailable: true, platform: 'android', secureContext: true }),
        'installed',
    );
    assert.equal(installGuidance('installed'), null);
});

test('the Install button appears only when the browser handed over its install prompt', () => {
    assert.equal(
        installState({ standalone: false, promptAvailable: true, platform: 'desktop', secureContext: true }),
        'available',
    );
    assert.equal(
        installState({ standalone: false, promptAvailable: false, platform: 'desktop', secureContext: true }),
        'browser-menu',
    );
});

test('iPhone and iPad get Add to Home Screen steps, never a fake install prompt', () => {
    const state = installState({ standalone: false, promptAvailable: false, platform: 'ios', secureContext: true });
    const guidance = installGuidance(state);

    assert.equal(state, 'ios-guide');
    assert.ok(guidance?.steps.some((step) => step.includes('Share')));
    assert.ok(guidance?.steps.some((step) => step.includes('Add to Home Screen')));
});

test('Safari on Mac gets Add to Dock steps; plain http gets the secure-connection explanation', () => {
    assert.equal(
        installState({ standalone: false, promptAvailable: false, platform: 'macos-safari', secureContext: true }),
        'safari-guide',
    );
    assert.match(installGuidance('safari-guide')?.steps.join(' ') ?? '', /Add to Dock/);
    assert.equal(
        installState({ standalone: false, promptAvailable: false, platform: 'android', secureContext: false }),
        'insecure',
    );
    assert.match(installGuidance('insecure')?.steps.join(' ') ?? '', /https:\/\//);
});

test('push support is feature-detected; iPhone needs the Home Screen app first', () => {
    const supported = { secureContext: true, serviceWorker: true, pushManager: true, notification: true, platform: 'android' as const, standalone: false };

    assert.equal(pushSupport(supported), 'supported');
    assert.equal(pushSupport({ ...supported, secureContext: false }), 'insecure');
    assert.equal(pushSupport({ ...supported, pushManager: false }), 'unsupported');
    assert.equal(pushSupport({ ...supported, platform: 'ios', standalone: false }), 'install-first');
    assert.equal(pushSupport({ ...supported, platform: 'ios', standalone: true }), 'supported');
});

test('notification states: denied is explained, never re-prompted; on requires permission and a subscription', () => {
    const base = { support: 'supported' as const, serverAvailable: true };

    assert.equal(pushState({ ...base, permission: 'denied', enabled: false }), 'blocked');
    assert.equal(pushState({ ...base, permission: 'default', enabled: false }), 'off');
    assert.equal(pushState({ ...base, permission: 'granted', enabled: false }), 'off');
    assert.equal(pushState({ ...base, permission: 'granted', enabled: true }), 'on');
    assert.equal(pushState({ ...base, serverAvailable: false, permission: 'granted', enabled: true }), 'unavailable');
    assert.equal(pushState({ support: 'install-first', serverAvailable: true, permission: null, enabled: false }), 'install-first');
    assert.equal(pushState({ support: 'unsupported', serverAvailable: true, permission: null, enabled: false }), 'unsupported');
});

test('the VAPID public key converts to the bytes subscribe() needs and detects a rotated key', () => {
    const key = 'BCVxsr7N_eNgVRqvHtD0zTZsEc6-VV-JvLexhqUzORcxaOzi6-AYWXvTBHm4bjyPjs7Vd8pZGH6SRpkNtoIAiw4';
    const bytes = urlBase64ToUint8Array(key);

    assert.equal(bytes.length, 65);
    assert.equal(bytes[0], 4);
    assert.equal(matchesApplicationServerKey(bytes.buffer, key), true);
    assert.equal(matchesApplicationServerKey(new Uint8Array(65).buffer, key), false);
    assert.equal(matchesApplicationServerKey(null, key), false);
});

test('the subscription body carries only delivery material and prefers aes128gcm', () => {
    const json = {
        endpoint: 'https://fcm.googleapis.com/fcm/send/abc',
        keys: { p256dh: 'p256', auth: 'secret' },
        expirationTime: null,
    };

    assert.deepEqual(subscriptionBody(json, ['aesgcm', 'aes128gcm']), {
        endpoint: json.endpoint,
        keys: { p256dh: 'p256', auth: 'secret' },
        content_encoding: 'aes128gcm',
    });
    assert.equal(subscriptionBody(json, ['aesgcm'])?.content_encoding, 'aesgcm');
    assert.equal(subscriptionBody(json, undefined)?.content_encoding, 'aes128gcm');
    assert.equal(subscriptionBody({ endpoint: json.endpoint, keys: {} }, ['aes128gcm']), null);
});
