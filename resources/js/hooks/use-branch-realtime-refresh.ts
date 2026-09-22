import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';

const REFRESH_DEBOUNCE_MS = 160;

type Options = {
    branchId: string;
    channel: string;
    events: readonly string[];
    only: string[];
    onEvent?: (event: Record<string, unknown>) => void;
};

export function useBranchRealtimeRefresh({
    branchId,
    channel,
    events,
    only,
    onEvent,
}: Options) {
    const connectionStatus = useConnectionStatus();
    const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const refreshInFlight = useRef(false);
    const refreshQueued = useRef(false);
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');
    const onlyRef = useRef(only);
    const onEventRef = useRef(onEvent);
    onlyRef.current = only;
    onEventRef.current = onEvent;

    const scheduleRefresh = useCallback((delay = REFRESH_DEBOUNCE_MS) => {
        if (refreshTimer.current !== null) {
            clearTimeout(refreshTimer.current);
        }

        refreshTimer.current = setTimeout(() => {
            refreshTimer.current = null;

            if (refreshInFlight.current) {
                refreshQueued.current = true;

                return;
            }

            refreshInFlight.current = true;
            router.reload({
                only: onlyRef.current,
                onFinish: () => {
                    refreshInFlight.current = false;

                    if (refreshQueued.current) {
                        refreshQueued.current = false;
                        scheduleRefresh(0);
                    }
                },
            });
        }, delay);
    }, []);

    useEcho<Record<string, unknown>>(
        `branch.${branchId}.${channel}`,
        [...events],
        (event) => {
            onEventRef.current?.(event);
            scheduleRefresh();
        },
        [branchId, channel, scheduleRefresh],
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

    useEffect(
        () => () => {
            if (refreshTimer.current !== null) {
                clearTimeout(refreshTimer.current);
            }
        },
        [],
    );

    return connectionStatus;
}
