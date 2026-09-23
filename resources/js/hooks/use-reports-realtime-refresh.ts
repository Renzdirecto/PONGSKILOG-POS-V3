import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';
import {
    createRealtimeRefresh,
    createReportsEventGuard,
} from '@/lib/realtime-refresh';

const REPORTS_FALLBACK_POLL_MS = 30_000;

/**
 * Keeps the Owner/Super Admin Dashboard and Reports live: the business-wide `reports.changed` signal (never carrying
 * figures) triggers a debounced partial reload of the authorized report props. With a Branch selected, other
 * Branches' signals are ignored; while the realtime connection is down the page polls instead (the only timer).
 *
 * A reload always requests the current URL, so it waits while the page runs its own visit (a period or filter
 * change) and an in-flight reload is cancelled when such a visit starts; one refresh follows the new page instead.
 */
export function useReportsRealtimeRefresh(
    only: string[],
    branchId: string | null,
    debounceMs = 1200,
): void {
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
    const acceptEvent = useMemo(
        () => createReportsEventGuard(branchId),
        [branchId],
    );

    useEcho<Record<string, unknown>>(
        'reports',
        ['.reports.changed'],
        (event) => {
            if (acceptEvent(event)) {
                scheduleRefresh();
            }
        },
        [acceptEvent, scheduleRefresh],
    );

    const shouldPoll = connectionStatus !== 'connected';

    /** One 30-second fallback check, only while realtime is unavailable, through the same guarded refresh. */
    useEffect(() => {
        if (!shouldPoll) {
            return;
        }
        const timer = window.setInterval(() => {
            if (document.visibilityState !== 'hidden') {
                scheduleRefresh(0);
            }
        }, REPORTS_FALLBACK_POLL_MS);

        return () => window.clearInterval(timer);
    }, [shouldPoll, scheduleRefresh]);

    useEffect(() => {
        /** The first connection after a page load needs no refetch: the server just rendered the page. */
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
        const removeStart = router.on('start', (event) => {
            if (!event.detail.visit.async) {
                navigations += 1;
                refresh.hold();
            }
        });
        const removeFinish = router.on('finish', (event) => {
            if (!event.detail.visit.async) {
                navigations = Math.max(0, navigations - 1);
                if (navigations === 0) {
                    refresh.release();
                }
            }
        });
        const recover = () => scheduleRefresh(0);
        window.addEventListener('online', recover);

        return () => {
            refresh.dispose();
            removeStart();
            removeFinish();
            window.removeEventListener('online', recover);
        };
    }, [refresh, scheduleRefresh]);
}
