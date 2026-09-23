import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import {
    createRealtimeRefresh,
    createReportsEventGuard,
    getAuditRealtimeFallbackAction,
} from '@/lib/realtime-refresh';

/**
 * Keeps the Owner/Super Admin Dashboard and Reports live: the business-wide `reports.changed` signal (never carrying
 * figures) triggers a debounced partial reload of the authorized report props. With a Branch selected, other
 * Branches' signals are ignored; while the realtime connection is down the page polls instead.
 */
export function useReportsRealtimeRefresh(
    only: string[],
    branchId: string | null,
    debounceMs = 1200,
): void {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const onlyRef = useRef(only);
    onlyRef.current = only;

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((onFinish) => {
                router.reload({ only: onlyRef.current, onFinish });
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

    const { start, stop } = usePoll(
        30_000,
        () => ({ only: onlyRef.current }),
        { autoStart: false },
    );

    useEffect(() => {
        const action = getAuditRealtimeFallbackAction(
            previousStatus.current,
            connectionStatus,
        );

        if (action.shouldPoll) {
            start();
        } else {
            stop();
        }

        if (action.shouldRefresh) {
            scheduleRefresh(0);
        }

        previousStatus.current = connectionStatus;
    }, [connectionStatus, scheduleRefresh, start, stop]);

    useEffect(() => {
        refresh.activate();

        const recover = () => scheduleRefresh(0);
        window.addEventListener('online', recover);

        return () => {
            stop();
            refresh.dispose();
            window.removeEventListener('online', recover);
        };
    }, [refresh, scheduleRefresh, stop]);
}
