/** Mirrors `LandingLove::MAX_TAPS`: the most taps one request may add. */
export const LOVE_MAX_TAPS = 20;
/** At most one request every two seconds, well inside the `landing-love` limiter however fast someone taps. */
export const LOVE_SEND_EVERY_MS = 2000;
const LOVE_ATTEMPTS = 3;

export type LoveBatch = { key: string; taps: number };
/** The shared total after a saved batch, or the HTTP status of a failure (0 when the server was not reached). */
export type LoveResult = { total: number } | { failed: number };

/** A lost connection or a server error is worth sending again; a rejection (422, 429, expired session) is not. */
export function loveRetryable(status: number): boolean {
    return status === 0 || status >= 500;
}

const SHOWN = 'pongskilog.loveShown';

/**
 * The highest count this browser tab has shown. A reload or a Back can hand the page a total that is a moment
 * old; starting from this number means a visitor never sees the count go backwards. It is a number only.
 */
export function loveFloor(): number {
    try {
        const shown = Number(sessionStorage.getItem(SHOWN));
        return Number.isSafeInteger(shown) && shown > 0 ? shown : 0;
    } catch {
        return 0;
    }
}
export function rememberLoveShown(shown: number): void {
    try {
        sessionStorage.setItem(SHOWN, String(shown));
    } catch {
        /* Private browsing may refuse storage; the count then simply starts from the server's total. */
    }
}

/**
 * Sends a batch while the page is being left. An ordinary request is cancelled with the page; a keepalive one is
 * finished by the browser. It carries the same CSRF token every other request of the page sends.
 */
export function sendLoveOnLeave(url: string, batch: LoveBatch): void {
    const name = 'XSRF-TOKEN=';
    const token =
        document.cookie
            .split('; ')
            .find((entry) => entry.startsWith(name))
            ?.slice(name.length) ?? '';
    void fetch(url, {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-XSRF-TOKEN': decodeURIComponent(token),
        },
        body: JSON.stringify(batch),
    }).catch(() => undefined);
}

/**
 * Tap-to-love without a request per tap. A tap shows at once; taps are sent as batches, each with one key that it
 * keeps when retried so the server counts it once. A batch the server finally refuses stays on this visitor's
 * screen only, and the number shown never drops. When the page is left, every tap not saved yet is handed over at
 * once (`leaving`), so quick taps followed by a reload or a link are not lost. `floor` is the count this visitor
 * already saw (`loveFloor`).
 */
export function createLoveQueue(
    total: number,
    send: (batch: LoveBatch, leaving?: boolean) => Promise<LoveResult>,
    newKey: () => string,
    onShown: (shown: number) => void,
    floor = 0,
) {
    let shared = total;
    let uncounted = 0;
    let pending = 0;
    let shown = Math.max(total, floor);
    let batch: LoveBatch | null = null;
    let attempts = 0;
    let sending = false;
    let nextSendAt = 0;
    let timer: ReturnType<typeof setTimeout> | undefined;
    let disposed = false;

    const show = () => {
        shown = Math.max(
            shown,
            shared + uncounted + pending + (batch?.taps ?? 0),
        );
        onShown(shown);
    };
    const schedule = () => {
        if (disposed || sending || timer !== undefined) return;
        if (batch === null && pending === 0) return;
        timer = setTimeout(flush, Math.max(0, nextSendAt - Date.now()));
    };
    const flush = async () => {
        timer = undefined;
        if (batch === null) {
            batch = { key: newKey(), taps: Math.min(pending, LOVE_MAX_TAPS) };
            pending -= batch.taps;
            attempts = 0;
        }
        sending = true;
        attempts += 1;
        nextSendAt = Date.now() + LOVE_SEND_EVERY_MS;
        const result = await send(batch).catch((): LoveResult => ({
            failed: 0,
        }));
        sending = false;
        if (disposed) return;
        if ('total' in result) {
            shared = result.total;
            batch = null;
        } else if (!loveRetryable(result.failed) || attempts >= LOVE_ATTEMPTS) {
            uncounted += batch.taps;
            batch = null;
        }
        show();
        schedule();
    };

    /** Hands every tap that is not saved yet to the server now; a batch already on its way keeps its key. */
    const leave = () => {
        clearTimeout(timer);
        timer = undefined;
        if (batch !== null) {
            void send(batch, true).catch(() => undefined);
            if (!sending) batch = null;
        }
        while (pending > 0) {
            const taps = Math.min(pending, LOVE_MAX_TAPS);
            pending -= taps;
            void send({ key: newKey(), taps }, true).catch(() => undefined);
        }
    };

    return {
        tap() {
            if (disposed) return;
            pending += 1;
            /* A tap always adds one to what this visitor sees, even when it started above the server's total. */
            shown += 1;
            show();
            schedule();
        },
        leave,
        dispose() {
            if (disposed) return;
            leave();
            disposed = true;
        },
    };
}
