/**
 * Customer-facing screen V2 (Phase 19.6A) — pure rules shared by the public screen, the POS header control and the
 * POS live-cart sync. The server stays the authority for everything shown; these helpers only decide presentation.
 *
 * Display state machine:
 *   persistent mode (server): ads | menu | customer_display — the two Store Operations controls are mutually exclusive
 *   and both may be off (ads is the default);
 *   temporary takeover (server, ephemeral): a committed order shown for 3 s (Dine In) / 5 s (Take Out) on top of
 *   whatever mode is selected. It never changes the mode, so the screen returns to it by itself.
 *   Menu + a live cart: the cart appears as a compact bounded panel above the still-scrollable Menu.
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

export type CustomerScreenTakeover = {
    id: string;
    order_number: string;
    order_type: OrderType;
    queue_position: number | null;
    remaining_ms: number;
    duration_ms: number;
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
    | 'ads'
    | 'menu'
    | 'customer_display';

/** Dine In 3 seconds, Take Out 5 seconds (frozen). The server sends the exact remaining time; this is the fallback. */
export const TAKEOVER_MS: Readonly<Record<OrderType, number>> = {
    dine_in: 3000,
    take_out: 5000,
};

/** Pressing a control: the other one turns off; pressing the active one again returns to Ads (the server decides). */
export function toggledMode(
    current: CustomerScreenMode,
    control: CustomerScreenControl,
): CustomerScreenMode {
    return current === control ? 'ads' : control;
}

/** Which layer fills the screen now. A takeover sits above every mode; an unpaired screen only shows its code. */
export function activeLayer(
    state: Pick<CustomerScreenState, 'status' | 'mode'>,
    takeoverShowing: boolean,
): CustomerScreenLayer {
    if (state.status !== 'paired') {
        return 'pairing';
    }
    if (takeoverShowing) {
        return 'takeover';
    }

    return state.mode;
}

/** The Live Cart panel shows in Menu mode only, and only while the paired station has lines. */
export function showsLiveCart(
    mode: CustomerScreenMode,
    cart: CustomerScreenCart | null,
): boolean {
    return mode === 'menu' && cart !== null && cart.lines.length > 0;
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

/** "You are #3 in the Dine-In queue" — only from the server-derived same-type position. */
export function queuePositionText(
    type: OrderType,
    position: number | null,
): string | null {
    if (position === null || position < 1) {
        return null;
    }

    return `You are #${position} in the ${type === 'dine_in' ? 'Dine-In' : 'Take-Out'} queue`;
}

/** Milliseconds the takeover stays up: the server's remaining time, bounded by its type's full duration. */
export function takeoverRemainingMs(takeover: CustomerScreenTakeover): number {
    const full = TAKEOVER_MS[takeover.order_type];

    return Math.max(0, Math.min(takeover.remaining_ms, full));
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
