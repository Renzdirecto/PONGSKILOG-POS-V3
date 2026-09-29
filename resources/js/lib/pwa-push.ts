import type { InstallPlatform } from './pwa-install';

/**
 * Browser notifications for New Kitchen Order, Order Ready and important alerts. Permission is only ever requested
 * from the Enable Notifications button; a denied permission is explained, never re-prompted. Push is best effort:
 * the in-app realtime views stay the authority for orders and alerts.
 */
export type PushSupport = 'supported' | 'install-first' | 'unsupported' | 'insecure';

export type PushState =
    | 'unsupported'
    | 'insecure'
    | 'install-first'
    | 'unavailable'
    | 'off'
    | 'on'
    | 'blocked';

export type PushSupportEnvironment = {
    secureContext: boolean;
    serviceWorker: boolean;
    pushManager: boolean;
    notification: boolean;
    platform: InstallPlatform;
    standalone: boolean;
};

export function pushSupport(environment: PushSupportEnvironment): PushSupport {
    if (!environment.secureContext) {
        return 'insecure';
    }
    /** iPhone and iPad deliver Web Push only to apps added to the Home Screen (iOS/iPadOS 16.4 or later). */
    if (environment.platform === 'ios' && !environment.standalone) {
        return 'install-first';
    }
    if (
        !environment.serviceWorker ||
        !environment.pushManager ||
        !environment.notification
    ) {
        return 'unsupported';
    }

    return 'supported';
}

export function pushState(input: {
    support: PushSupport;
    serverAvailable: boolean;
    permission: NotificationPermission | null;
    enabled: boolean;
}): PushState {
    if (input.support !== 'supported') {
        return input.support;
    }
    if (!input.serverAvailable) {
        return 'unavailable';
    }
    if (input.permission === 'denied') {
        return 'blocked';
    }

    return input.permission === 'granted' && input.enabled ? 'on' : 'off';
}

/** The VAPID public key (URL-safe base64) as the byte array `PushManager.subscribe()` expects. */
export function urlBase64ToUint8Array(value: string): Uint8Array<ArrayBuffer> {
    const padded = value + '='.repeat((4 - (value.length % 4)) % 4);
    const binary = atob(padded.replaceAll('-', '+').replaceAll('_', '/'));
    const bytes = new Uint8Array(new ArrayBuffer(binary.length));
    for (let index = 0; index < binary.length; index += 1) {
        bytes[index] = binary.charCodeAt(index);
    }

    return bytes;
}

/** Whether an existing browser subscription was made for this server's current VAPID key. */
export function matchesApplicationServerKey(
    existing: ArrayBuffer | null | undefined,
    publicKey: string,
): boolean {
    if (!existing) {
        return false;
    }
    const expected = urlBase64ToUint8Array(publicKey);
    const actual = new Uint8Array(existing);

    return (
        actual.length === expected.length &&
        actual.every((byte, index) => byte === expected[index])
    );
}

export type PushSubscriptionBody = {
    endpoint: string;
    keys: { p256dh: string; auth: string };
    content_encoding: 'aes128gcm' | 'aesgcm';
};

/**
 * The request body for this browser's subscription: only what delivery needs. Prefers the standard `aes128gcm`
 * encoding (the only one Safari supports).
 */
export function subscriptionBody(
    subscription: {
        endpoint?: string;
        keys?: Record<string, string | undefined>;
    },
    supportedEncodings: readonly string[] | undefined,
): PushSubscriptionBody | null {
    const p256dh = subscription.keys?.p256dh;
    const auth = subscription.keys?.auth;
    if (!subscription.endpoint || !p256dh || !auth) {
        return null;
    }
    const encodings = supportedEncodings ?? ['aes128gcm'];

    return {
        endpoint: subscription.endpoint,
        keys: { p256dh, auth },
        content_encoding:
            encodings.includes('aes128gcm') || !encodings.includes('aesgcm')
                ? 'aes128gcm'
                : 'aesgcm',
    };
}
