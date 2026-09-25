import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useMemo, useRef } from 'react';
import { handleRevalidationException } from '@/hooks/use-user-context-realtime';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';
import {
    createBranchEventGuard,
    createRealtimeRefresh,
} from '@/lib/realtime-refresh';

export function usePosQrRealtime(branchId: string) {
    const connection = useConnectionStatus();
    const previousConnection = useRef(connection);
    const hasConnected = useRef(connection === 'connected');
    const accept = useMemo(() => createBranchEventGuard(branchId), [branchId]);
    const refresh = useMemo(
        () =>
            createRealtimeRefresh(
                (finish) =>
                    router.reload({
                        only: ['qrWaitingCount', 'loadedQr'],
                        onHttpException: handleRevalidationException,
                        onNetworkError: () => false,
                        onFinish: finish,
                    }),
                35,
            ),
        [branchId],
    );
    useEcho<Record<string, unknown>>(
        `branch.${branchId}.pos`,
        ['.qr.order_submitted', '.qr.order_loaded', '.qr.order_archived', '.qr.order_released', '.qr.order_restored'],
        (event) => {
            if (accept(event)) refresh.schedule();
        },
        [branchId, accept, refresh],
    );
    /** The server just rendered the QR state: refetch only after a reconnect, when signals may have been missed. */
    useEffect(() => {
        if (
            shouldRefetchCatalogAfterConnectionChange(
                previousConnection.current,
                connection,
                hasConnected.current,
            )
        ) {
            refresh.schedule(0);
        }
        if (connection === 'connected') {
            hasConnected.current = true;
        }
        previousConnection.current = connection;
    }, [refresh, connection]);
    useEffect(() => {
        refresh.activate();
        const retry = () => refresh.schedule(0);
        window.addEventListener('online', retry);
        return () => {
            refresh.dispose();
            window.removeEventListener('online', retry);
        };
    }, [refresh]);
}
