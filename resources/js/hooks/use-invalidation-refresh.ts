import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';

type InvalidationRefreshOptions = {
    /** Private channel to listen on. */
    channel: string;
    /** Broadcast name, with the leading dot (for example '.staff.changed'). */
    event: string;
    /** Page props to partially reload; the server re-authorizes and re-scopes them. */
    only: string[];
    debounceMs?: number;
};

/**
 * Keeps a management page (Staff, Access Control) live from a payload-free invalidation signal: one debounced partial
 * reload per burst, one after the realtime connection recovers, and none while the page runs its own visit (filters,
 * saves), in which case a single refresh follows that visit. There is no polling timer.
 */
export function useInvalidationRefresh({
    channel,
    event,
    only,
    debounceMs = 400,
}: InvalidationRefreshOptions): void {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');
    const onlyRef = useRef(only);
    onlyRef.current = only;

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((onFinish) => {
                let cancel: (() => void) | undefined;
                router.reload({
                    only: onlyRef.current,
                    onCancelToken: (token) => {
                        cancel = token.cancel;
                    },
                    onFinish,
                });

                return () => cancel?.();
            }, debounceMs),
        [debounceMs],
    );
    const scheduleRefresh = refresh.schedule;

    useEcho<Record<string, unknown>>(
        channel,
        [event],
        () => scheduleRefresh(),
        [scheduleRefresh],
        'private',
    );

    useEffect(() => {
        if (
            shouldRefetchCatalogAfterConnectionChange(
                previousStatus.current,
                connectionStatus,
                hasConnected.current,
            )
        ) {
            scheduleRefresh(0);
        }
        if (connectionStatus === 'connected') {
            hasConnected.current = true;
        }
        previousStatus.current = connectionStatus;
    }, [connectionStatus, scheduleRefresh]);

    useEffect(() => {
        refresh.activate();
        let navigations = 0;
        const removeStart = router.on('start', (visitEvent) => {
            if (!visitEvent.detail.visit.async) {
                navigations += 1;
                refresh.hold();
            }
        });
        const removeFinish = router.on('finish', (visitEvent) => {
            if (!visitEvent.detail.visit.async) {
                navigations = Math.max(0, navigations - 1);
                if (navigations === 0) {
                    refresh.release();
                }
            }
        });

        return () => {
            refresh.dispose();
            removeStart();
            removeFinish();
        };
    }, [refresh]);
}

/** Renders nothing; subscribes only while mounted, so a page mounts it only when it has a channel to listen on. */
export function InvalidationRefresh(props: InvalidationRefreshOptions) {
    useInvalidationRefresh(props);

    return null;
}
