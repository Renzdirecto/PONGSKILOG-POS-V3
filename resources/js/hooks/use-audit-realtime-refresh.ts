import { useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo } from 'react';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';

export function useAuditRealtimeRefresh(only: string[]): void {
    const refresh = useMemo(
        () =>
            createRealtimeRefresh((onFinish) => {
                router.reload({ only, onFinish });
            }, 200),
        [only],
    );

    useEcho<Record<string, unknown>>(
        'audit-trail',
        ['audit.recorded'],
        () => refresh.schedule(),
        [refresh],
    );

    useEffect(() => {
        refresh.activate();

        return () => refresh.dispose();
    }, [refresh]);
}
