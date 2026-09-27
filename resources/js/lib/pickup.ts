import type { PushSupport } from './pwa-push';

/**
 * Takeout pickup page rules (Phase 19.6). The page is read-only: the server projection decides the status, the overall
 * and Take Out queue positions, the order summary (historical snapshots) and whether the receipt can be viewed; the
 * customer may only opt in to (or out of) this order's Ready notification. Scanning the QR alone never subscribes
 * anything — only the explicit Notify me button, after the browser's permission prompt.
 */
export type PickupStatusValue = 'preparing' | 'ready' | 'done' | 'unavailable';

export type PickupSummaryLine = {
    name: string;
    quantity: number;
    details: string[];
    instructions: string[];
    amount: string;
};

export type PickupLinks = {
    facebook: string | null;
    website: string | null;
    maps: string | null;
};

export type PickupStatusData = {
    order_number: string;
    order_type: 'take_out';
    status: PickupStatusValue;
    /** Position in the Take Out queue (null once the order is no longer waiting). */
    queue_position: number | null;
    /** Position among all active orders, Dine In and Take Out together. */
    overall_position: number | null;
    summary: { items: PickupSummaryLine[]; subtotal: string; total: string };
    receipt_available: boolean;
    links: PickupLinks;
    notifications: {
        available: boolean;
        public_key: string | null;
        subscribed: boolean;
    };
    /** The optional foreground Buzz sound; null when no file is installed (the Buzz is then vibration + notification). */
    buzz_sound_url: string | null;
    channel: string;
};

export type PickupNotifyState =
    | PushSupport
    | 'not-needed'
    | 'unavailable'
    | 'blocked'
    | 'off'
    | 'on';

/** The customer pickup worker: its own file and scope, separate from the staff app's `/sw.js`. */
export const PICKUP_WORKER_URL = '/pickup-sw.js';
export const PICKUP_WORKER_SCOPE = '/pickup/';

/** What the pickup worker posts to this order's open page when a Buzz arrives (alongside its notification). */
export const PICKUP_BUZZ_MESSAGE = 'pickup.buzz';

export function isPickupBuzzMessage(data: unknown): boolean {
    return (
        typeof data === 'object' &&
        data !== null &&
        (data as { type?: unknown }).type === PICKUP_BUZZ_MESSAGE
    );
}

/**
 * The open page's extra Buzz cue. The push notification is the Buzz itself (OS sound and vibration, also when the phone
 * is locked); only a page in the foreground adds a vibration and — when the Branch installed one — its own sound. A
 * hidden page does nothing extra, and a missing sound file simply means no sound.
 */
export function pickupBuzzCue(input: {
    visible: boolean;
    soundUrl: string | null;
}): { vibrate: boolean; sound: string | null } {
    if (!input.visible) {
        return { vibrate: false, sound: null };
    }

    return {
        vibrate: true,
        sound:
            typeof input.soundUrl === 'string' && input.soundUrl.startsWith('/')
                ? input.soundUrl
                : null,
    };
}

export function pickupStatusView(status: PickupStatusValue): {
    title: string;
    message: string;
    tone: 'amber' | 'green' | 'neutral';
} {
    switch (status) {
        case 'ready':
            return {
                title: 'Ready for pickup',
                message: 'Please proceed to the counter.',
                tone: 'green',
            };
        case 'done':
            return {
                title: 'Picked up',
                message: 'Enjoy your meal. Salamat po!',
                tone: 'neutral',
            };
        case 'unavailable':
            return {
                title: 'Order not active',
                message: 'Please ask the cashier about this order.',
                tone: 'neutral',
            };
        default:
            return {
                title: 'Preparing',
                message: 'We’re making your order.',
                tone: 'amber',
            };
    }
}

/**
 * The queue part of the page: the Take Out position (emphasized) and the overall position — only while the order is
 * still being prepared. Ready / Done / inactive orders are no longer waiting, so no position is shown for them.
 */
export function pickupQueueView(
    pickup: Pick<
        PickupStatusData,
        'status' | 'queue_position' | 'overall_position'
    >,
): { takeOut: number | null; overall: string | null } | null {
    if (pickup.status !== 'preparing') {
        return null;
    }
    const takeOut =
        pickup.queue_position !== null && pickup.queue_position > 0
            ? pickup.queue_position
            : null;
    const overall =
        pickup.overall_position !== null && pickup.overall_position > 0
            ? `#${pickup.overall_position} in the overall queue`
            : null;

    return takeOut === null && overall === null ? null : { takeOut, overall };
}

/** The customer link buttons: only configured http(s) links, in a fixed order, never a placeholder. */
export function pickupLinkButtons(
    links: PickupLinks,
): { key: keyof PickupLinks; label: string; url: string }[] {
    const labels: Record<keyof PickupLinks, string> = {
        facebook: 'Facebook',
        website: 'Website',
        maps: 'Maps',
    };

    return (['facebook', 'website', 'maps'] as const).flatMap((key) => {
        const url = links[key];

        return typeof url === 'string' && /^https?:\/\/[^\s]+$/i.test(url)
            ? [{ key, label: labels[key], url }]
            : [];
    });
}

/**
 * What the notification section shows. "on" needs both sides: this browser holds a pickup subscription with the
 * permission granted, and the server has a subscription for this order (a rejected endpoint is removed server-side,
 * which turns it back to "off").
 */
export function pickupNotifyState(input: {
    support: PushSupport;
    status: PickupStatusValue;
    serverAvailable: boolean;
    permission: NotificationPermission | null;
    browserSubscribed: boolean;
    serverSubscribed: boolean;
}): PickupNotifyState {
    if (input.status === 'done' || input.status === 'unavailable') {
        return 'not-needed';
    }
    if (input.support !== 'supported') {
        return input.support;
    }
    if (!input.serverAvailable) {
        return 'unavailable';
    }
    if (input.permission === 'denied') {
        return 'blocked';
    }

    return input.permission === 'granted' &&
        input.browserSubscribed &&
        input.serverSubscribed
        ? 'on'
        : 'off';
}

export function pickupNotifyMessage(state: PickupNotifyState): string {
    switch (state) {
        case 'on':
            return 'Notifications are on. We’ll buzz this phone when your order is ready.';
        case 'off':
            return 'Get a notification on this phone when your order is ready.';
        case 'blocked':
            return 'Notifications are blocked for this site. Allow them in your browser settings, or keep this page open — it updates live.';
        case 'install-first':
            return 'On iPhone, notifications only work for apps on the Home Screen. Keep this page open — it updates live.';
        case 'insecure':
            return 'Notifications need a secure (https) connection. Keep this page open — it updates live.';
        case 'unsupported':
            return 'This browser can’t show notifications. Keep this page open — it updates live.';
        case 'unavailable':
            return 'Notifications are not available right now. Keep this page open — it updates live.';
        default:
            return '';
    }
}
