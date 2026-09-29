import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useMemo, useRef } from 'react';
import {
    createBranchEventGuard,
    createRealtimeRefresh,
} from '@/lib/realtime-refresh';
import { STORE_CLOSE_POS_EVENTS } from '@/lib/store-close';

/** Operational events only invalidate; the pre-close summary is always refetched from the server. */
export function useStoreCloseRealtime(
    branchId: string,
    refreshPreview: () => Promise<void>,
) {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const refreshRef = useRef(refreshPreview);
    refreshRef.current = refreshPreview;
    const guard = useMemo(() => createBranchEventGuard(branchId), [branchId]);
    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                void refreshRef.current().finally(finish);
            }, 250),
        [],
    );

    useEcho<Record<string, unknown>>(
        `branch.${branchId}.pos`,
        [...STORE_CLOSE_POS_EVENTS],
        (event) => {
            if (guard(event)) {
                refresh.schedule();
            }
        },
        [branchId, guard, refresh],
    );
    useEcho<Record<string, unknown>>(
        `branch.${branchId}.store-session`,
        ['.store.expense_recorded'],
        (event) => {
            if (guard(event)) {
                refresh.schedule();
            }
        },
        [branchId, guard, refresh],
    );

    useEffect(() => {
        if (
            previousStatus.current !== 'connected' &&
            connectionStatus === 'connected'
        ) {
            refresh.schedule(0);
        }
        previousStatus.current = connectionStatus;
    }, [connectionStatus, refresh]);

    useEffect(() => {
        refresh.activate();
        const recover = () => refresh.schedule(0);
        window.addEventListener('online', recover);
        return () => {
            refresh.dispose();
            window.removeEventListener('online', recover);
        };
    }, [refresh]);

    return connectionStatus;
}
