import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useCallback, useEffect, useMemo, useRef } from 'react';
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
    const additionalProps = useRef(new Set<string>());
    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                const only = ['catalog', ...additionalProps.current];
                additionalProps.current.clear();
                router.reload({
                    only,
                    preserveUrl: true,
                    onHttpException: handleRevalidationException,
                    onNetworkError: () => false,
                    onFinish: finish,
                });
            }, REFRESH_DEBOUNCE_MS),
        [branchId],
    );
    const refreshCatalog = useCallback(
        (only: string[] = []) => {
            only.forEach((prop) => additionalProps.current.add(prop));
            router.flushByCacheTags('workspaces');
            refresh.schedule();
        },
        [refresh],
    );

    useEcho<PosCatalogRealtimeEvent>(
        `branch.${branchId}.inventory`,
        [...POS_CATALOG_REALTIME_EVENTS],
        () => refreshCatalog(),
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

    return { connectionStatus, refreshCatalog };
}
