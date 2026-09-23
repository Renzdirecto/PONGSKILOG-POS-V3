import { useEcho } from '@laravel/echo-react';
import { useMemo, useRef } from 'react';
import { createBranchEventGuard } from '@/lib/realtime-refresh';
import type { StoreClosedRealtimeEvent } from '@/types/store-close';

/** Operational workspaces reload authoritative Store state when any cashier closes the branch Store Session. */
export function useStoreClosedRealtime(
    branchId: string,
    channel: 'pos' | 'kitchen',
    onClosed: (event: StoreClosedRealtimeEvent) => void,
) {
    const onClosedRef = useRef(onClosed);
    onClosedRef.current = onClosed;
    const guard = useMemo(() => createBranchEventGuard(branchId), [branchId]);

    useEcho<StoreClosedRealtimeEvent>(
        `branch.${branchId}.${channel}`,
        ['.store.closed'],
        (event) => {
            if (guard(event as unknown as Record<string, unknown>)) {
                onClosedRef.current(event);
            }
        },
        [branchId, channel, guard],
    );
}
