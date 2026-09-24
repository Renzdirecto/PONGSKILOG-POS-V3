import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router, useHttp } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';
import { unreadCount as unreadCountRoute } from '@/routes/super-admin/notifications';

type NotificationSignalOptions = {
    userId: number;
    onSignal: () => void;
};

/**
 * Listens on the viewer's own private channel for the invalidation-only `notifications.changed` signal and runs one
 * debounced refresh per burst, plus one after the realtime connection recovers. There is no polling timer.
 */
function useNotificationSignal({
    userId,
    onSignal,
}: NotificationSignalOptions): void {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const onSignalRef = useRef(onSignal);
    onSignalRef.current = onSignal;

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                onSignalRef.current();
                finish();
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
            previousStatus.current !== 'connected' &&
            connectionStatus === 'connected'
        ) {
            scheduleRefresh(0);
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
        onSignal: () => {
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
                });
        },
    });

    return unread;
}

/** Keeps the Notifications page list live by partially reloading its own props after a signal. */
export function useNotificationsPageRefresh(userId: number): void {
    useNotificationSignal({
        userId,
        onSignal: () =>
            router.reload({ only: ['notifications', 'unreadCount'] }),
    });
}
