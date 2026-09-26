import type { PushSupport } from './pwa-push';

/**
 * Takeout pickup page rules (Phase 19.6B). The page is read-only: the server projection decides the status and queue
 * position; the customer may only opt in to (or out of) this order's Ready notification. Scanning the QR alone never
 * subscribes anything — only the explicit Notify me button, after the browser's permission prompt.
 */
export type PickupStatusValue = 'preparing' | 'ready' | 'done' | 'unavailable';

export type PickupStatusData = {
    order_number: string;
    order_type: 'take_out';
    status: PickupStatusValue;
    queue_position: number | null;
    notifications: {
        available: boolean;
        public_key: string | null;
        subscribed: boolean;
    };
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

export function pickupQueueText(position: number | null): string | null {
    return position !== null && position > 0
        ? `You are #${position} in the Take-Out queue`
        : null;
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
