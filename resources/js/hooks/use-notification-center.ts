import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router, useHttp } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { handleRevalidationException } from '@/hooks/use-user-context-realtime';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';
import { unreadCount as unreadCountRoute } from '@/routes/super-admin/notifications';

type NotificationSignalOptions = {
    userId: number;
    /** Runs one refresh; calls `finish` when it completed, so refreshes never overlap or land out of order. */
    onSignal: (finish: () => void) => void;
};

/**
 * Listens on the viewer's own private channel for the invalidation-only `notifications.changed` signal and runs one
 * debounced refresh per burst (one at a time), plus one after the realtime connection recovers — not on the first
 * connect, right after the server rendered the page. There is no polling timer.
 */
function useNotificationSignal({
    userId,
    onSignal,
}: NotificationSignalOptions): void {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');
    const onSignalRef = useRef(onSignal);
    onSignalRef.current = onSignal;

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                onSignalRef.current(finish);
            }, 400),
        [],
    );
    const scheduleRefresh = refresh.schedule;

    useEcho<Record<string, unknown>>(
        `App.Models.User.${userId}`,
        ['.notifications.changed'],
        () => scheduleRefresh(),
        [scheduleRefresh],
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
}

/**
 * The real unread count for the Control Center bell: server-rendered on each visit, then refetched from the cheap
 * unread-count endpoint when a signal arrives (never a full page reload, never a guessed number).
 */
export function useUnreadNotifications(
    userId: number,
    initialUnread: number,
): number {
    const [unread, setUnread] = useState(initialUnread);
    const [syncedInitial, setSyncedInitial] = useState(initialUnread);
    const request = useHttp<Record<string, never>, { unread: number }>({});

    if (syncedInitial !== initialUnread) {
        setSyncedInitial(initialUnread);
        setUnread(initialUnread);
    }

    useNotificationSignal({
        userId,
        onSignal: (finish) => {
            request
                .get(unreadCountRoute.url(), {
                    headers: { Accept: 'application/json' },
                })
                .then((response) => {
                    if (typeof response?.unread === 'number') {
                        setUnread(response.unread);
                    }
                })
                .catch(() => {
                    // Keep the last confirmed count; the next signal or visit refreshes it.
                })
                .finally(finish);
        },
    });

    return unread;
}

/**
 * Keeps a page's notification-driven props live by partially reloading them after a signal on the viewer's own
 * channel (the Notifications list, or the Executive Dashboard's security and attention summaries).
 */
export function useNotificationsPageRefresh(
    userId: number,
    only: string[] = ['notifications', 'unreadCount'],
): void {
    const onlyRef = useRef(only);
    onlyRef.current = only;

    useNotificationSignal({
        userId,
        onSignal: (finish) =>
            router.reload({
                only: onlyRef.current,
                preserveUrl: true,
                onHttpException: handleRevalidationException,
                onNetworkError: () => false,
                onFinish: finish,
            }),
    });
}
