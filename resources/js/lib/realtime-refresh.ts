/**
 * One request at a time, with one trailing refresh for signals received in flight.
 *
 * `hold()` pauses refreshes while the page runs its own navigation (for example a filter change): a pending refresh
 * waits, an in-flight one is cancelled through the cancel function `refresh` may return, and `release()` runs one
 * refresh against the page the navigation produced, so a stale reload never lands on top of newer filters.
 */
export function createRealtimeRefresh(
    refresh: (finish: () => void) => void | (() => void),
    debounceMs = 160,
) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight = false;
    let queued = false;
    let disposed = false;
    let held = false;
    let cancelInFlight: (() => void) | undefined;
    const schedule = (delay = debounceMs) => {
        if (disposed) {
            return;
        }
        if (inFlight || held) {
            queued = true;
            return;
        }
        if (timer !== undefined) {
            return;
        }
        timer = setTimeout(() => {
            timer = undefined;
            inFlight = true;
            let finished = false;
            const cancel = refresh(() => {
                finished = true;
                inFlight = false;
                cancelInFlight = undefined;
                if (queued) {
                    queued = false;
                    schedule(0);
                }
            });
            cancelInFlight = finished || typeof cancel !== 'function' ? undefined : cancel;
        }, delay);
    };
    return {
        schedule,
        hold: () => {
            held = true;
            if (timer !== undefined) {
                clearTimeout(timer);
                timer = undefined;
                queued = true;
            }
            if (inFlight) {
                queued = true;
                cancelInFlight?.();
            }
        },
        release: () => {
            if (!held) {
                return;
            }
            held = false;
            if (queued && !inFlight) {
                queued = false;
                schedule(0);
            }
        },
        activate: () => {
            disposed = false;
        },
        dispose: () => {
            disposed = true;
            clearTimeout(timer);
            timer = undefined;
            queued = false;
            held = false;
        },
    };
}

/** Submitted QR orders can safely reload assets; unsent carts must stay intact. */
export function createQrVersionRecovery(
    trackingId: string | undefined,
    reload: () => void,
) {
    let reloading = false;
    return (event: {
        detail: { versionChange: boolean };
        preventDefault: () => void;
    }) => {
        if (!trackingId || !event.detail.versionChange) return;
        event.preventDefault();
        if (reloading) return;
        reloading = true;
        reload();
    };
}

export function createBranchEventGuard(branchId: string) {
    const seen = new Set<string>();
    return (event: Record<string, unknown>) => {
        if (event.branch_id !== branchId) {
            return false;
        }
        if (typeof event.event_id === 'string') {
            if (seen.has(event.event_id)) {
                return false;
            }
            seen.add(event.event_id);
            if (seen.size > 512) {
                seen.delete(seen.values().next().value!);
            }
        }
        return true;
    };
}

/**
 * Accepts a business-wide `reports.changed` signal once: every Branch for All Branches (null), otherwise only the
 * selected Branch.
 */
export function createReportsEventGuard(branchId: string | null) {
    const seen = new Set<string>();
    return (event: Record<string, unknown>) => {
        if (typeof event.branch_id !== 'string') {
            return false;
        }
        if (branchId !== null && event.branch_id !== branchId) {
            return false;
        }
        if (typeof event.event_id === 'string') {
            if (seen.has(event.event_id)) {
                return false;
            }
            seen.add(event.event_id);
            if (seen.size > 512) {
                seen.delete(seen.values().next().value!);
            }
        }
        return true;
    };
}

export function getAuditRealtimeFallbackAction(
    previousStatus: string,
    connectionStatus: string,
): { shouldPoll: boolean; shouldRefresh: boolean } {
    return {
        shouldPoll: connectionStatus !== 'connected',
        shouldRefresh:
            previousStatus !== 'connected' &&
            connectionStatus === 'connected',
    };
}
