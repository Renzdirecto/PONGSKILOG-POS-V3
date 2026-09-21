import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useRef } from 'react';
import {
    POS_CATALOG_REALTIME_EVENTS,
    shouldRefetchCatalogAfterConnectionChange,
    type PosCatalogRealtimeEvent,
} from '@/lib/pos-catalog-realtime';

const REFRESH_DEBOUNCE_MS = 160;

export function usePosCatalogRealtime(branchId: string) {
    const connectionStatus = useConnectionStatus();
    const refreshTimer = useRef<ReturnType<typeof setTimeout> | null>(null);
    const refreshInFlight = useRef(false);
    const refreshQueued = useRef(false);
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');

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
                only: ['catalog'],
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

    useEcho<PosCatalogRealtimeEvent>(
        `branch.${branchId}.inventory`,
        [...POS_CATALOG_REALTIME_EVENTS],
        () => scheduleRefresh(),
        [branchId, scheduleRefresh],
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
