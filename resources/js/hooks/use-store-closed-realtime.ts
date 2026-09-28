import { useEcho } from '@laravel/echo-react';
import { useMemo, useRef } from 'react';
import { createBranchEventGuard } from '@/lib/realtime-refresh';
import type { StoreClosedRealtimeEvent } from '@/types/store-close';

/**
 * Operational workspaces reload authoritative Store state when any cashier closes the branch Store Session, and (Phase
 * 20) when one opens it, so every Store Operations page leaves Store Closed without a manual refresh.
 */
export function useStoreClosedRealtime(
    branchId: string,
    channel: 'pos' | 'kitchen',
    onClosed: (event: StoreClosedRealtimeEvent) => void,
    onOpened?: () => void,
) {
    const onClosedRef = useRef(onClosed);
    onClosedRef.current = onClosed;
    const onOpenedRef = useRef(onOpened);
    onOpenedRef.current = onOpened;
    const guard = useMemo(() => createBranchEventGuard(branchId), [branchId]);

    useEcho<StoreClosedRealtimeEvent | { event_type: 'store.opened' }>(
        `branch.${branchId}.${channel}`,
        ['.store.closed', '.store.opened'],
        (event) => {
            if (!guard(event as unknown as Record<string, unknown>)) {
                return;
            }
            if (event.event_type === 'store.opened') {
                onOpenedRef.current?.();
            } else {
                onClosedRef.current(event as StoreClosedRealtimeEvent);
            }
        },
        [branchId, channel, guard],
    );
}
