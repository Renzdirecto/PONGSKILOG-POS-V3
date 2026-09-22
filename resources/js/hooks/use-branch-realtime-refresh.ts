import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';

import {
    createBranchEventGuard,
    createRealtimeRefresh,
} from '@/lib/realtime-refresh';

type Options = {
    branchId: string;
    channel: string;
    events: readonly string[];
    only: string[];
    debounceMs?: number;
    onEvent?: (event: Record<string, unknown>) => void;
};

export function useBranchRealtimeRefresh({
    branchId,
    channel,
    events,
    only,
    onEvent,
    debounceMs = 160,
}: Options) {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');
    const onlyRef = useRef(only);
    const onEventRef = useRef(onEvent);
    onlyRef.current = only;
    onEventRef.current = onEvent;

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((onFinish) => {
                router.reload({ only: onlyRef.current, onFinish });
            }, debounceMs),
        [branchId, channel, debounceMs],
    );
    const scheduleRefresh = refresh.schedule;
    const acceptEvent = useMemo(
        () => createBranchEventGuard(branchId),
        [branchId, channel],
    );

    useEcho<Record<string, unknown>>(
        `branch.${branchId}.${channel}`,
        [...events],
        (event) => {
            if (!acceptEvent(event)) {
                return;
            }
            onEventRef.current?.(event);
            scheduleRefresh();
        },
        [branchId, channel, scheduleRefresh, acceptEvent],
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

    useEffect(() => {
        refresh.activate();
        return () => refresh.dispose();
    }, [refresh]);

    return { connectionStatus, scheduleRefresh };
}
