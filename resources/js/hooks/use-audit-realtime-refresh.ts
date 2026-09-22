import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router, usePoll } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import {
    createRealtimeRefresh,
    getAuditRealtimeFallbackAction,
} from '@/lib/realtime-refresh';

export function useAuditRealtimeRefresh(only: string[]): void {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const onlyRef = useRef(only);
    onlyRef.current = only;

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((onFinish) => {
                router.reload({ only: onlyRef.current, onFinish });
            }, 200),
        [],
    );
    const scheduleRefresh = refresh.schedule;

    useEcho<Record<string, unknown>>(
        'audit-trail',
        ['.audit.recorded'],
        () => scheduleRefresh(),
        [scheduleRefresh],
    );

    const { start, stop } = usePoll(
        10_000,
        () => ({ only: onlyRef.current }),
        { autoStart: false },
    );

    useEffect(() => {
        const action = getAuditRealtimeFallbackAction(
            previousStatus.current,
            connectionStatus,
        );

        if (action.shouldPoll) {
            start();
        } else {
            stop();
        }

        if (action.shouldRefresh) {
            scheduleRefresh(0);
        }

        previousStatus.current = connectionStatus;
    }, [connectionStatus, scheduleRefresh, start, stop]);

    useEffect(() => {
        refresh.activate();

        const recover = () => scheduleRefresh(0);
        window.addEventListener('online', recover);

        return () => {
            stop();
            refresh.dispose();
            window.removeEventListener('online', recover);
        };
    }, [refresh, scheduleRefresh, stop]);
}
