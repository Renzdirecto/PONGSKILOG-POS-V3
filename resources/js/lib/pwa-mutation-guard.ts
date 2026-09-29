/**
 * PWA Phase 1 is internet-first: while the server is not confirmed reachable, every application server write
 * (POST, PUT, PATCH, DELETE) is blocked before it is sent — payments, Pay Later, settlement, Void, edits, Store
 * Open/Close, expenses, inventory and Ingredient stock, Kitchen status, Staff and access changes alike. Nothing is
 * queued, retried later or answered with a fake success. This is UX only: the backend still authorizes and validates
 * every request.
 */
export const OFFLINE_WRITE_MESSAGE =
    "You're offline. Reconnect to continue this operation.";

export function isServerWrite(method: string | undefined): boolean {
    return ['post', 'put', 'patch', 'delete'].includes(
        (method ?? 'get').toLowerCase(),
    );
}

/**
 * Writes the app sends by itself (not an operation the user started): blocked without the offline message, because
 * their own screens already explain the failure (order number, receipt QR, recipe availability).
 */
const BACKGROUND_WRITES = [
    /\/pos\/recipe-capacity$/,
    /\/qr\/[^/]+\/recipe-capacity$/,
    /\/pos\/orders\/reservations$/,
    /\/pos\/orders\/[^/]+\/receipt-share$/,
];

export function isBackgroundWrite(url: string): boolean {
    let path = url;
    try {
        path = new URL(url, 'http://pongskilog.invalid').pathname;
    } catch {
        path = url;
    }

    return BACKGROUND_WRITES.some((pattern) => pattern.test(path));
}

export type WriteDecision = 'send' | 'block' | 'block-silently';

/** What the guard does with one request: reads always go out; writes only while the server is confirmed online. */
export function writeDecision(request: {
    method: string | undefined;
    url: string;
    serverWritesAllowed: boolean;
}): WriteDecision {
    if (!isServerWrite(request.method) || request.serverWritesAllowed) {
        return 'send';
    }

    return isBackgroundWrite(request.url) ? 'block-silently' : 'block';
}

/** Thrown instead of sending a write while offline; nothing reached the server. */
export class OfflineWriteBlockedError extends Error {
    readonly offlineBlocked = true;
    readonly url: string;

    constructor(url = '') {
        super(OFFLINE_WRITE_MESSAGE);
        this.name = 'OfflineWriteBlockedError';
        this.url = url;
    }
}

export function isOfflineWriteBlock(error: unknown): boolean {
    return (
        typeof error === 'object' &&
        error !== null &&
        (error as { offlineBlocked?: unknown }).offlineBlocked === true
    );
}
