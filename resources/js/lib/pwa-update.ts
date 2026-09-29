/**
 * App updates never interrupt work. A new service worker waits (no automatic skipWaiting or reload), the app shows
 * "PONGSKILOG update available", and it activates only when someone chooses Update now while nothing is at risk.
 *
 * Screens register blockers for transient work a reload would destroy — a POS cart, a loaded QR order with additions,
 * an open payment, a write still being saved. While any blocker is active, Update now is refused with its reason; the
 * new version simply waits for a safe moment. Only the window that asked for the update reloads, exactly once.
 */
export type UpdateSource = 'service-worker' | 'server' | 'activated-elsewhere';

export type UpdateSnapshot = {
    available: boolean;
    source: UpdateSource | null;
    applying: boolean;
    snoozedUntil: number | null;
    blockers: readonly string[];
};

export type WaitingWorker = { postMessage: (message: unknown) => void };

export type UpdateEnvironment = {
    reload: () => void;
    now: () => number;
    setTimeout: (callback: () => void, delayMs: number) => unknown;
};

export type ApplyResult = 'blocked' | 'applying' | 'reloading';

export const SKIP_WAITING_MESSAGE = { type: 'SKIP_WAITING' } as const;

/** "Later" hides the prompt for an hour; the header status keeps showing that an update is ready. */
export const UPDATE_SNOOZE_MS = 60 * 60 * 1000;

/** If the new worker never takes control, reload anyway (once, and only if still safe). */
export const ACTIVATION_FALLBACK_MS = 8_000;

export function createUpdateController(environment: UpdateEnvironment) {
    const blockers = new Map<string, string>();
    const listeners = new Set<() => void>();
    let waiting: WaitingWorker | null = null;
    let initiated = false;
    let reloading = false;
    let snapshot: UpdateSnapshot = {
        available: false,
        source: null,
        applying: false,
        snoozedUntil: null,
        blockers: [],
    };

    const update = (next: Partial<UpdateSnapshot>) => {
        snapshot = { ...snapshot, ...next };
        listeners.forEach((listener) => listener());
    };
    const reasons = () => [...new Set(blockers.values())];
    /** Reload once, and never over work that became unsafe meanwhile. */
    const reloadOnce = (): boolean => {
        if (reloading || blockers.size > 0) {
            return false;
        }
        reloading = true;
        environment.reload();

        return true;
    };

    return {
        getSnapshot: (): UpdateSnapshot => snapshot,
        subscribe: (listener: () => void) => {
            listeners.add(listener);

            return () => {
                listeners.delete(listener);
            };
        },
        /** A screen reports transient work (reason) or that it is safe again (null). */
        setBlocker: (id: string, reason: string | null) => {
            const current = blockers.get(id) ?? null;
            if (current === reason) {
                return;
            }
            if (reason === null) {
                blockers.delete(id);
            } else {
                blockers.set(id, reason);
            }
            update({ blockers: reasons() });
        },
        /** A new service worker is installed and waiting. */
        markWaiting: (worker: WaitingWorker) => {
            waiting = worker;
            update({ available: true, source: 'service-worker' });
        },
        /** The server now serves newer assets than this window loaded (Inertia asset version changed). */
        markServerVersionChanged: () => {
            if (!snapshot.available) {
                update({ available: true, source: 'server' });
            }
        },
        /** The controlling worker changed: reload if this window asked for it, otherwise ask to reload when safe. */
        handleControllerChange: () => {
            waiting = null;
            if (initiated && reloadOnce()) {
                return;
            }
            initiated = false;
            update({
                available: true,
                source: 'activated-elsewhere',
                applying: false,
            });
        },
        snooze: () => {
            update({ snoozedUntil: environment.now() + UPDATE_SNOOZE_MS });
        },
        isPromptDue: (): boolean =>
            snapshot.available &&
            (snapshot.snoozedUntil === null ||
                environment.now() >= snapshot.snoozedUntil),
        apply: (): ApplyResult => {
            if (blockers.size > 0) {
                return 'blocked';
            }
            if (reloading) {
                return 'reloading';
            }
            if (waiting !== null) {
                initiated = true;
                update({ applying: true });
                waiting.postMessage(SKIP_WAITING_MESSAGE);
                environment.setTimeout(() => {
                    if (!reloadOnce()) {
                        initiated = false;
                        update({ applying: false });
                    }
                }, ACTIVATION_FALLBACK_MS);

                return 'applying';
            }

            return reloadOnce() ? 'reloading' : 'blocked';
        },
    };
}

export type UpdateController = ReturnType<typeof createUpdateController>;
