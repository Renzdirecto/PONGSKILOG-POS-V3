import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import { handleRevalidationException } from '@/hooks/use-user-context-realtime';
import {
    POS_CATALOG_REALTIME_EVENTS,
    shouldRefetchCatalogAfterConnectionChange,
    type PosCatalogRealtimeEvent,
} from '@/lib/pos-catalog-realtime';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';

const REFRESH_DEBOUNCE_MS = 160;

/**
 * Keeps the POS catalog (availability, stock) current: one reload at a time with one trailing reload for signals that
 * arrive meanwhile, a fixed debounce (a burst of signals cannot postpone it forever), nothing after unmount, and a
 * revoked session sent to the workspace instead of an error dialog.
 */
export function usePosCatalogRealtime(branchId: string) {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');
    const refresh = useMemo(
        () =>
            createRealtimeRefresh(
                (finish) =>
                    router.reload({
                        only: ['catalog'],
                        preserveUrl: true,
                        onHttpException: handleRevalidationException,
                        onNetworkError: () => false,
                        onFinish: finish,
                    }),
                REFRESH_DEBOUNCE_MS,
            ),
        [branchId],
    );

    useEcho<PosCatalogRealtimeEvent>(
        `branch.${branchId}.inventory`,
        [...POS_CATALOG_REALTIME_EVENTS],
        () => refresh.schedule(),
        [branchId, refresh],
    );

    useEffect(() => {
        if (
            shouldRefetchCatalogAfterConnectionChange(
                previousStatus.current,
                connectionStatus,
                hasConnected.current,
            )
        ) {
            refresh.schedule(0);
        }

        if (connectionStatus === 'connected') {
            hasConnected.current = true;
        }

        previousStatus.current = connectionStatus;
    }, [connectionStatus, refresh]);

    useEffect(() => {
        refresh.activate();

        return () => refresh.dispose();
    }, [refresh]);

    return connectionStatus;
}
