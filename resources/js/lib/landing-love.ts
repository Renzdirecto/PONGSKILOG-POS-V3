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

/**
 * Tap-to-love without a request per tap. A tap shows at once; taps are sent as batches, each with one key that it
 * keeps when retried so the server counts it once. A batch the server finally refuses stays on this visitor's
 * screen only, and the number shown never drops.
 */
export function createLoveQueue(
    total: number,
    send: (batch: LoveBatch) => Promise<LoveResult>,
    newKey: () => string,
    onShown: (shown: number) => void,
) {
    let shared = total;
    let uncounted = 0;
    let pending = 0;
    let shown = total;
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

    return {
        tap() {
            pending += 1;
            show();
            schedule();
        },
        dispose() {
            disposed = true;
            clearTimeout(timer);
        },
    };
}
