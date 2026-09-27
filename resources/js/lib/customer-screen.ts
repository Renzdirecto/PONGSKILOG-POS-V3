/**
 * Customer-facing screen V2 (Phase 19.6) — pure rules shared by the public screen, the POS header control and the
 * POS live-cart sync. The server stays the authority for everything shown; these helpers only decide presentation.
 *
 * Display state machine:
 *   persistent mode (server, one column): ads | menu | customer_display — MENU and CUSTOMER DISPLAY (on the POS header
 *   and on the screen's own header) are mutually exclusive, pressing the active one again returns to Ads;
 *   Ads + a live cart: a full-screen order summary replaces the slideshow until the cart is cleared or paid;
 *   Menu + a live cart: the cart appears as a compact bounded panel above the still-scrollable Menu;
 *   Customer Display: the normal order-number board (a cart does not replace it);
 *   order confirmation (server, ephemeral): every committed Dine In / Take Out order is confirmed above everything.
 *   Its countdown (the Branch's configured seconds) starts only once the number and, for Take Out, the pickup QR are
 *   on screen. The Menu closes when an order is confirmed (Ads afterwards); a selected Customer Display stays.
 */
export type CustomerScreenMode = 'ads' | 'menu' | 'customer_display';
export type CustomerScreenControl = Exclude<CustomerScreenMode, 'ads'>;
export type OrderType = 'dine_in' | 'take_out';

export type CustomerScreenCartLine = {
    key: string;
    name: string;
    quantity: number;
    details: string[];
    instructions: string[];
    amount: string;
};

export type CustomerScreenCart = {
    lines: CustomerScreenCartLine[];
    total: string;
    item_count: number;
    order_type: OrderType | null;
    updated_at: string;
};

export type CustomerScreenQueueRow = {
    position: number;
    order_number: string;
    order_type: OrderType;
    current: boolean;
};

export type CustomerScreenTakeover = {
    id: string;
    order_number: string;
    order_type: OrderType;
    duration_ms: number;
    /** Null while the confirmation waits to be shown; the time left once it is showing (e.g. after a reload). */
    remaining_ms: number | null;
    overall_position: number | null;
    type_position: number | null;
    queue: CustomerScreenQueueRow[];
    queue_total: number;
    pickup: { url: string; qr_image: string } | null;
};

export type CustomerOrderBoardData = {
    is_open: boolean;
    preparing: string[];
    ready: string[];
};

export type CustomerScreenState = {
    status: 'unpaired' | 'paired';
    branch: { name: string } | null;
    mode: CustomerScreenMode;
    cart: CustomerScreenCart | null;
    takeover: CustomerScreenTakeover | null;
    board: CustomerOrderBoardData | null;
    sound_url: string | null;
    channels: { screen: string; catalog?: string; board?: string } | null;
};

export type CustomerMenuProduct = {
    key: string;
    category: string;
    name: string;
    description: string | null;
    price: string;
    available: boolean;
    status: 'available' | 'sold_out' | 'unavailable';
    image_url: string | null;
    sizes: { name: string; price: string; available: boolean }[];
    has_options: boolean;
};

export type CustomerMenuData = {
    categories: { key: string; name: string; icon_key: string }[];
    products: CustomerMenuProduct[];
    expires_at: string;
};

export type CustomerScreenPlaylist = {
    items: {
        key: string;
        type: 'image' | 'video';
        url: string;
        duration_ms: number;
    }[];
    expires_at: string;
};

export type CustomerScreenLayer =
    | 'pairing'
    | 'takeover'
    | 'order_summary'
    | 'ads'
    | 'menu'
    | 'customer_display';

/** The Branch's confirmation duration is 3–15 s; anything else from the wire is clamped. */
export const TAKEOVER_MIN_MS = 3000;
export const TAKEOVER_MAX_MS = 15000;

/** The longest the screen waits for the pickup QR image before starting the countdown anyway (it never hangs). */
export const TAKEOVER_READY_TIMEOUT_MS = 4000;

function hasLines(cart: CustomerScreenCart | null | undefined): boolean {
    return cart !== null && cart !== undefined && cart.lines.length > 0;
}

/**
 * Which layer fills the screen now. An order confirmation sits above every mode; an unpaired screen only shows its
 * code; in Ads mode a live cart replaces the slideshow with the full order summary.
 */
export function activeLayer(
    state: Pick<CustomerScreenState, 'status' | 'mode'> & {
        cart?: CustomerScreenCart | null;
    },
    takeoverShowing: boolean,
): CustomerScreenLayer {
    if (state.status !== 'paired') {
        return 'pairing';
    }
    if (takeoverShowing) {
        return 'takeover';
    }
    if (state.mode === 'ads' && hasLines(state.cart)) {
        return 'order_summary';
    }

    return state.mode;
}

/** The Live Cart panel shows in Menu mode only (split with the Menu), and only while the paired station has lines. */
export function showsLiveCart(
    mode: CustomerScreenMode,
    cart: CustomerScreenCart | null,
): boolean {
    return mode === 'menu' && hasLines(cart);
}

/**
 * The confirmation still to show: a server takeover this screen has not finished yet. A refetch during or after it
 * never restarts it (finished ids are remembered by the page).
 */
export function pendingTakeover(
    current: CustomerScreenTakeover | null,
    finished: ReadonlySet<string>,
): CustomerScreenTakeover | null {
    if (current === null || finished.has(current.id)) {
        return null;
    }

    return current.remaining_ms === null || current.remaining_ms > 0
        ? current
        : null;
}

/**
 * How long the confirmation stays once it is on screen: the full configured duration, or only the time left when the
 * server says it was already showing (a reload in the middle). Always within 3–15 s.
 */
export function takeoverShowMs(takeover: CustomerScreenTakeover): number {
    const full = Math.max(
        TAKEOVER_MIN_MS,
        Math.min(TAKEOVER_MAX_MS, takeover.duration_ms),
    );

    return takeover.remaining_ms === null
        ? full
        : Math.max(0, Math.min(takeover.remaining_ms, full));
}

/** A Take Out confirmation with a pickup QR waits for the QR image; Dine In (or a Take Out without a QR) is ready now. */
export function takeoverWaitsForQr(takeover: CustomerScreenTakeover): boolean {
    return takeover.order_type === 'take_out' && takeover.pickup !== null;
}

/** "You are #27 overall" — the true position among all active orders, even when only a window of rows is shown. */
export function overallPositionText(position: number | null): string | null {
    return position === null || position < 1
        ? null
        : `You are #${position} overall`;
}

export function typeQueueLabel(type: OrderType): string {
    return type === 'dine_in' ? 'DINE IN QUEUE' : 'TAKE OUT QUEUE';
}

/** The next / previous advertisement, wrapping around. */
export function stepSlide(
    index: number,
    length: number,
    direction: 1 | -1,
): number {
    if (length <= 0) {
        return 0;
    }

    return (((index + direction) % length) + length) % length;
}

/** A horizontal swipe: left → next, right → previous; short or mostly vertical moves are not swipes. */
export function swipeDirection(
    deltaX: number,
    deltaY: number,
    threshold = 48,
): 'next' | 'previous' | null {
    if (Math.abs(deltaX) < threshold || Math.abs(deltaX) < Math.abs(deltaY)) {
        return null;
    }

    return deltaX < 0 ? 'next' : 'previous';
}

type SlideClock = {
    now: () => number;
    set: (callback: () => void, ms: number) => unknown;
    clear: (handle: unknown) => void;
};

/**
 * One advertisement countdown that can be paused (press and hold) and resumed with the time it had left. Starting a
 * new slide (timer, swipe or arrow) cancels the previous countdown, so there is only ever one pending timer.
 */
export function createSlideTimer(
    onElapsed: () => void,
    clock: SlideClock = {
        now: () => Date.now(),
        set: (callback, ms) => setTimeout(callback, ms),
        clear: (handle) =>
            clearTimeout(handle as ReturnType<typeof setTimeout>),
    },
) {
    let handle: unknown;
    let remaining = 0;
    let startedAt = 0;
    let paused = false;
    const cancel = () => {
        if (handle !== undefined) {
            clock.clear(handle);
            handle = undefined;
        }
    };
    const run = () => {
        startedAt = clock.now();
        handle = clock.set(() => {
            handle = undefined;
            onElapsed();
        }, remaining);
    };

    return {
        start: (ms: number) => {
            cancel();
            paused = false;
            remaining = Math.max(0, ms);
            run();
        },
        pause: () => {
            if (paused || handle === undefined) return;
            cancel();
            remaining = Math.max(0, remaining - (clock.now() - startedAt));
            paused = true;
        },
        resume: () => {
            if (!paused) return;
            paused = false;
            run();
        },
        stop: () => {
            cancel();
            paused = false;
        },
        isPaused: () => paused,
        isRunning: () => handle !== undefined,
    };
}

/** Whether this browser can put the page in fullscreen (kiosk browsers and iPhone Safari often cannot). */
export function fullscreenSupported(
    doc: {
        fullscreenEnabled?: boolean;
        documentElement?: { requestFullscreen?: unknown };
    } | null,
): boolean {
    return (
        doc !== null &&
        doc.fullscreenEnabled === true &&
        typeof doc.documentElement?.requestFullscreen === 'function'
    );
}

/**
 * Headers the POS adds to Pay Now / Pay Later so the server can confirm the committed order on this station's paired
 * screen from the same request: the station id and the last cart send (so an older cart send in flight cannot bring
 * the paid cart back). Nothing is added without a paired screen.
 */
export function customerScreenCommitHeaders(
    stationId: string | null,
    paired: boolean,
    lastCart: { instance: string; sequence: number },
): Record<string, string> {
    if (!paired || stationId === null) {
        return {};
    }

    return {
        'X-POS-Station': stationId,
        'X-Customer-Screen-Cart': `${lastCart.instance}:${lastCart.sequence}`,
    };
}

/** Lines that are new or whose quantity/amount changed since the previous projection (for a short highlight). */
export function changedLineKeys(
    previous: readonly CustomerScreenCartLine[] | null,
    next: readonly CustomerScreenCartLine[],
): string[] {
    if (previous === null) {
        return [];
    }
    const before = new Map(previous.map((line) => [line.key, line]));

    return next
        .filter((line) => {
            const old = before.get(line.key);

            return (
                old === undefined ||
                old.quantity !== line.quantity ||
                old.amount !== line.amount
            );
        })
        .map((line) => line.key);
}

export function orderTypeText(type: OrderType): string {
    return type === 'dine_in' ? 'DINE IN' : 'TAKE OUT';
}

/** "AB3K7Q" → "AB3 K7Q" for reading across a counter. */
export function formatPairingCode(code: string): string {
    return code.length === 6 ? `${code.slice(0, 3)} ${code.slice(3)}` : code;
}

/** When to refetch signed media / menu image links: a few minutes before they expire, never sooner than 1 minute. */
export function refreshDelayMs(expiresAt: string, now = Date.now()): number {
    const expires = new Date(expiresAt).getTime();
    if (Number.isNaN(expires)) {
        return 5 * 60_000;
    }

    return Math.max(60_000, expires - now - 5 * 60_000);
}

/**
 * Numbers every cart send of one POS page instance. The server ignores an older number from the same instance, so a
 * delayed request can never overwrite a newer cart.
 */
export function createCartSequencer(instance: string) {
    let sequence = 0;

    return {
        instance,
        next: () => {
            sequence += 1;

            return { instance, sequence };
        },
        last: () => ({ instance, sequence }),
    };
}

export const POS_STATION_STORAGE_KEY = 'pongskilog.pos-station';

/** A stable random id for this browser installation (never a name a person typed), or null when storage is blocked. */
export function readStationId(
    storage: Pick<Storage, 'getItem' | 'setItem'> | null,
    createId: () => string,
): string | null {
    if (storage === null) {
        return null;
    }
    try {
        const existing = storage.getItem(POS_STATION_STORAGE_KEY);
        if (existing && /^[A-Za-z0-9-]{16,64}$/.test(existing)) {
            return existing;
        }
        const created = createId();
        storage.setItem(POS_STATION_STORAGE_KEY, created);

        return storage.getItem(POS_STATION_STORAGE_KEY) === created
            ? created
            : null;
    } catch {
        return null;
    }
}

/** A connection problem is shown honestly: the Live Cart is marked as possibly out of date while disconnected. */
export function connectionNotice(status: string): string | null {
    if (status === 'connected') {
        return null;
    }
    if (status === 'unavailable' || status === 'failed') {
        return 'Live updates unavailable';
    }

    return 'Reconnecting…';
}
