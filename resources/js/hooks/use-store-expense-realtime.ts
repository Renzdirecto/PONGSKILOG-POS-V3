import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { useEffect, useMemo, useRef } from 'react';
import { createBranchEventGuard, createRealtimeRefresh } from '@/lib/realtime-refresh';
import type { StoreExpenseRealtimeEvent } from '@/lib/store-session-expense';

export function useStoreExpenseRealtime(
    branchId: string,
    refreshSession: () => Promise<void>,
) {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const refreshRef = useRef(refreshSession);
    refreshRef.current = refreshSession;
    const guard = useMemo(() => createBranchEventGuard(branchId), [branchId]);
    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                void refreshRef.current().finally(finish);
            }, 180),
        [],
    );

    useEcho<StoreExpenseRealtimeEvent>(
        `branch.${branchId}.store-session`,
        ['.store.expense_recorded'],
        (event) => {
            if (guard(event as unknown as Record<string, unknown>)) {
                refresh.schedule();
            }
        },
        [branchId, guard, refresh],
    );

    /** Stock shown for restocks and adjustments follows the branch inventory invalidation. */
    useEcho<Record<string, unknown>>(
        `branch.${branchId}.inventory`,
        ['.inventory.changed'],
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
