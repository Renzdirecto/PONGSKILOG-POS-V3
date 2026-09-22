import { router } from '@inertiajs/react';
import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useMemo } from 'react';
import {
    createBranchEventGuard,
    createRealtimeRefresh,
} from '@/lib/realtime-refresh';

export function usePosQrRealtime(branchId: string) {
    const connection = useConnectionStatus();
    const accept = useMemo(() => createBranchEventGuard(branchId), [branchId]);
    const refresh = useMemo(
        () =>
            createRealtimeRefresh(
                (finish) =>
                    router.reload({
                        only: ['qrWaitingCount', 'loadedQr'],
                        onFinish: finish,
                    }),
                35,
            ),
        [branchId],
    );
    useEcho<Record<string, unknown>>(
        `branch.${branchId}.pos`,
        ['.qr.order_submitted', '.qr.order_loaded', '.qr.order_archived'],
        (event) => {
            if (accept(event)) refresh.schedule();
        },
        [branchId, accept, refresh],
    );
    useEffect(() => {
        refresh.activate();
        if (connection === 'connected') refresh.schedule(0);
        const retry = () => refresh.schedule(0);
        window.addEventListener('online', retry);
        return () => {
            refresh.dispose();
            window.removeEventListener('online', retry);
        };
    }, [refresh, connection]);
}
