import { router, usePoll } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { handleRevalidationException } from '@/hooks/use-user-context-realtime';

/** Manual and timed refreshes share one request; navigation cancels a report for the old filters. */
export function useWebsiteAnalyticsRefresh() {
    const [refreshing, setRefreshing] = useState(false);
    const [failed, setFailed] = useState(false);
    const inFlight = useRef(false);
    const navigations = useRef(0);
    const cancel = useRef<(() => void) | null>(null);

    const options = () => ({
        only: ['report'],
        showProgress: false,
        onBefore: () =>
            !inFlight.current &&
            navigations.current === 0 &&
            document.visibilityState !== 'hidden' &&
            navigator.onLine,
        onCancelToken: (token: { cancel: () => void }) => {
            cancel.current = token.cancel;
        },
        onStart: () => {
            inFlight.current = true;
            setRefreshing(true);
        },
        onSuccess: () => setFailed(false),
        onError: () => setFailed(true),
        onHttpException: (response: { status: number }) => {
            setFailed(true);
            return handleRevalidationException(response);
        },
        onNetworkError: () => {
            setFailed(true);
            return false as const;
        },
        onFinish: () => {
            inFlight.current = false;
            cancel.current = null;
            setRefreshing(false);
        },
    });

    usePoll(30_000, options, { mode: 'rest' });

    useEffect(() => {
        const removeStart = router.on('start', (event) => {
            if (!event.detail.visit.async) {
                navigations.current += 1;
                cancel.current?.();
            }
        });
        const removeFinish = router.on('finish', (event) => {
            if (!event.detail.visit.async) {
                navigations.current = Math.max(0, navigations.current - 1);
            }
        });
        return () => {
            removeStart();
            removeFinish();
            cancel.current?.();
        };
    }, []);

    return { refreshing, failed, refresh: () => router.reload(options()) };
}
