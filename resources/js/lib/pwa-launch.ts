/**
 * Basic launch recovery for the installed app. Refreshes, deep links and notification taps already keep their URL,
 * and the start URL (`/workspace`) routes each account to its own landing page. On top of that, a freshly launched
 * app window that the start URL redirected returns to the last top-level screen used on this device — for example a
 * Kitchen or Customer Display tablet goes back to its board.
 *
 * Only a same-origin path from a fixed list is remembered: never a query string (except the POS QR view), a signed
 * link, a token, an open dialog or any cart or payment state. The server authorizes the restored page like any visit.
 */
export const LAST_ROUTE_STORAGE_KEY = 'pongskilog.pwa.last-route';

/** A remembered screen older than this is not restored. */
export const LAST_ROUTE_MAX_AGE_MS = 12 * 60 * 60 * 1000;

const RESTORABLE_PATHS = [
    /^\/workspaces\/(kitchen|customer-display|cashier|cashier-dashboard|transaction-history|owner|reports|transactions|audit-trail|void-orders|staff)$/,
    /^\/workspaces\/super-admin(\/(staff|notifications|access-control))?$/,
    /^\/workspaces\/operations(\/(overview|ingredients|recipes|stock|pamamalengke|purchases))?$/,
    /^\/(products|inventory|branches|categories|modifier-groups)$/,
];

/** The safe, restorable form of a URL, or null when it must never be remembered. */
export function restorableRoute(url: string, origin: string): string | null {
    let parsed: URL;
    try {
        parsed = new URL(url, origin);
    } catch {
        return null;
    }
    if (
        parsed.origin !== origin ||
        !RESTORABLE_PATHS.some((pattern) => pattern.test(parsed.pathname))
    ) {
        return null;
    }

    return parsed.pathname === '/workspaces/cashier' &&
        parsed.searchParams.get('view') === 'qr'
        ? '/workspaces/cashier?view=qr'
        : parsed.pathname;
}

export type StoredRoute = { path: string; savedAt: number };

export function parseStoredRoute(value: string | null): StoredRoute | null {
    if (!value) {
        return null;
    }
    try {
        const parsed = JSON.parse(value) as Partial<StoredRoute>;

        return typeof parsed.path === 'string' &&
            typeof parsed.savedAt === 'number'
            ? { path: parsed.path, savedAt: parsed.savedAt }
            : null;
    } catch {
        return null;
    }
}

/**
 * The screen to return to on this launch, or null. Only for an installed app window's first page, reached through a
 * redirect from the start URL (a deep link or notification tap arrives without one), while signed in.
 */
export function launchRestoreTarget(input: {
    standalone: boolean;
    firstPageOfWindow: boolean;
    navigationType: string | null;
    redirectCount: number;
    signedIn: boolean;
    stored: StoredRoute | null;
    currentUrl: string;
    origin: string;
    now: number;
}): string | null {
    if (
        !input.standalone ||
        !input.firstPageOfWindow ||
        input.navigationType !== 'navigate' ||
        input.redirectCount < 1 ||
        !input.signedIn ||
        input.stored === null ||
        input.now - input.stored.savedAt > LAST_ROUTE_MAX_AGE_MS
    ) {
        return null;
    }
    const target = restorableRoute(input.stored.path, input.origin);
    const current = restorableRoute(input.currentUrl, input.origin);

    return target !== null && target !== current ? target : null;
}
