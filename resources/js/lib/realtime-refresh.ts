/** One request at a time, with one trailing refresh for signals received in flight. */
export function createRealtimeRefresh(
    refresh: (finish: () => void) => void,
    debounceMs = 160,
) {
    let timer: ReturnType<typeof setTimeout> | undefined;
    let inFlight = false;
    let queued = false;
    let disposed = false;
    const schedule = (delay = debounceMs) => {
        if (disposed) {
            return;
        }
        if (inFlight) {
            queued = true;
            return;
        }
        if (timer !== undefined) {
            return;
        }
        timer = setTimeout(() => {
            timer = undefined;
            inFlight = true;
            refresh(() => {
                inFlight = false;
                if (queued) {
                    queued = false;
                    schedule(0);
                }
            });
        }, delay);
    };
    return {
        schedule,
        activate: () => {
            disposed = false;
        },
        dispose: () => {
            disposed = true;
            clearTimeout(timer);
            timer = undefined;
            queued = false;
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
