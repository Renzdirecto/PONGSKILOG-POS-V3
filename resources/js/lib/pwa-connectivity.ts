/**
 * The one canonical connectivity state of the app (PWA Phase 1, internet-first).
 *
 * - `online`: the server is confirmed reachable; server writes are allowed.
 * - `reconnecting`: the network came back (or a request failed) and the app is verifying the server and reloading
 *   authoritative page data; writes stay blocked until that succeeds.
 * - `offline`: the device has no network, or the server stayed unreachable after several attempts.
 *
 * `navigator.onLine` is only a hint: recovery is confirmed against the server (`probe`, Laravel's `/up`) and completed
 * by an authoritative reload (`revalidate`). Retries back off to one attempt every 30 s, pause while the page is
 * hidden, and stop entirely once online: an online app never polls to prove connectivity.
 */
export type ConnectivityPhase = 'online' | 'offline' | 'reconnecting';

export type ConnectivitySnapshot = {
    phase: ConnectivityPhase;
    /** Last successful server contact, for "Last synced" messaging only; never used for stock or money decisions. */
    lastSyncedAt: number | null;
};

export type RevalidationOutcome = 'revalidated' | 'unreachable';

export type ConnectivityEnvironment = {
    /** Whether the server answered (Laravel's `/up`); must not throw on network failure. */
    probe: () => Promise<boolean>;
    /** Reload authoritative session, Branch and page data from the server. */
    revalidate: () => Promise<RevalidationOutcome>;
    isNavigatorOnline: () => boolean;
    isDocumentVisible: () => boolean;
    now: () => number;
    setTimeout: (callback: () => void, delayMs: number) => unknown;
    clearTimeout: (handle: unknown) => void;
};

/** Delay before each verification attempt while not online: at once, then backing off to one try every 30 s. */
export const RECONNECT_DELAYS_MS = [0, 2_000, 5_000, 10_000, 20_000, 30_000];

/** Failed attempts after which a still-unreachable server is presented as Offline instead of Reconnecting. */
export const OFFLINE_AFTER_FAILED_ATTEMPTS = 3;

export function reconnectDelay(attempt: number): number {
    const index = Math.min(Math.max(attempt, 0), RECONNECT_DELAYS_MS.length - 1);

    return RECONNECT_DELAYS_MS[index];
}

export function createConnectivityMonitor(environment: ConnectivityEnvironment) {
    let snapshot: ConnectivitySnapshot = {
        phase: environment.isNavigatorOnline() ? 'online' : 'offline',
        lastSyncedAt: environment.now(),
    };
    const listeners = new Set<() => void>();
    let attempt = 0;
    let timer: unknown = null;
    let verifying = false;
    let disposed = false;

    const update = (next: Partial<ConnectivitySnapshot>) => {
        const merged = { ...snapshot, ...next };
        if (
            merged.phase === snapshot.phase &&
            merged.lastSyncedAt === snapshot.lastSyncedAt
        ) {
            return;
        }
        snapshot = merged;
        listeners.forEach((listener) => listener());
    };
    const cancelTimer = () => {
        if (timer !== null) {
            environment.clearTimeout(timer);
            timer = null;
        }
    };
    const unconfirmedPhase = (): ConnectivityPhase =>
        attempt < OFFLINE_AFTER_FAILED_ATTEMPTS ? 'reconnecting' : 'offline';
    const isOnline = () => snapshot.phase === 'online';

    /** The next attempt, unless online, already verifying, device-offline (wait for `online`) or hidden (paused). */
    const schedule = (delayMs: number) => {
        cancelTimer();
        if (
            disposed ||
            verifying ||
            snapshot.phase === 'online' ||
            !environment.isNavigatorOnline() ||
            !environment.isDocumentVisible()
        ) {
            return;
        }
        timer = environment.setTimeout(() => {
            timer = null;
            void verify();
        }, delayMs);
    };

    const verify = async () => {
        if (disposed || verifying || snapshot.phase === 'online') {
            return;
        }
        if (!environment.isNavigatorOnline()) {
            update({ phase: 'offline' });

            return;
        }
        cancelTimer();
        verifying = true;
        update({ phase: unconfirmedPhase() });
        let outcome: RevalidationOutcome = 'unreachable';
        try {
            const reachable = await environment.probe();
            if (reachable && environment.isNavigatorOnline() && !disposed) {
                update({ phase: 'reconnecting' });
                outcome = await environment.revalidate();
            }
        } catch {
            outcome = 'unreachable';
        }
        verifying = false;
        /** Another signal may have confirmed the server while this attempt was waiting. */
        if (disposed || isOnline()) {
            return;
        }
        if (!environment.isNavigatorOnline()) {
            update({ phase: 'offline' });

            return;
        }
        if (outcome === 'revalidated') {
            attempt = 0;
            update({ phase: 'online', lastSyncedAt: environment.now() });

            return;
        }
        attempt += 1;
        update({ phase: unconfirmedPhase() });
        schedule(reconnectDelay(attempt));
    };

    /** A fresh signal (network back, request failure, manual retry): verify at once from the first attempt. */
    const begin = () => {
        if (disposed) {
            return;
        }
        attempt = 0;
        if (snapshot.phase === 'online') {
            update({ phase: 'reconnecting' });
        }
        void verify();
    };

    return {
        getSnapshot: (): ConnectivitySnapshot => snapshot,
        subscribe: (listener: () => void) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },
        /** Server writes are allowed only while the server is confirmed reachable. */
        allowsServerWrites: (): boolean => snapshot.phase === 'online',
        /** Browser `offline`: stop verifying until the network returns. */
        handleOffline: () => {
            cancelTimer();
            attempt = 0;
            update({ phase: 'offline' });
        },
        /** Browser `online`: verify the server, then revalidate. */
        handleOnline: begin,
        handleVisibilityChange: () => {
            if (!environment.isDocumentVisible()) {
                cancelTimer();

                return;
            }
            if (snapshot.phase !== 'online') {
                void verify();
            }
        },
        /** A request never got a response: the server may be gone even though the browser reports a network. */
        reportNetworkFailure: () => {
            if (snapshot.phase === 'online') {
                begin();
            }
        },
        /** Any server response (success or HTTP error) proves contact. */
        reportServerResponse: () => {
            if (snapshot.phase === 'online') {
                update({ lastSyncedAt: environment.now() });
            } else if (!verifying) {
                void verify();
            }
        },
        retry: begin,
        dispose: () => {
            disposed = true;
            cancelTimer();
            listeners.clear();
        },
    };
}

export type ConnectivityMonitor = ReturnType<typeof createConnectivityMonitor>;

/** What the status pill and its panel say for each state (text and icon, never color alone). */
export function connectivityCopy(phase: ConnectivityPhase): {
    label: string;
    title: string;
    detail: string;
} {
    switch (phase) {
        case 'reconnecting':
            return {
                label: 'Reconnecting…',
                title: 'Reconnecting to PONGSKILOG',
                detail: 'Checking the connection and refreshing this screen. Changes wait until PONGSKILOG is back online.',
            };
        case 'offline':
            return {
                label: 'Offline',
                title: "You're offline",
                detail: 'This screen shows what was last loaded and may be out of date. Orders, payments and other changes need internet; nothing is saved offline.',
            };
        default:
            return {
                label: 'Online',
                title: 'Online',
                detail: 'Connected to PONGSKILOG.',
            };
    }
}

/** "Last synced 10:42 PM": the last server contact, as a local clock time. */
export function lastSyncedText(
    timestamp: number | null,
    formatTime: (date: Date) => string = (date) =>
        date.toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' }),
): string | null {
    return timestamp === null
        ? null
        : `Last synced ${formatTime(new Date(timestamp))}`;
}
