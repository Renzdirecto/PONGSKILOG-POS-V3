import { useConnectionStatus, useEcho } from '@laravel/echo-react';
import { router } from '@inertiajs/react';
import { useEffect, useMemo, useRef } from 'react';
import { shouldRefetchCatalogAfterConnectionChange } from '@/lib/pos-catalog-realtime';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';
import {
    createUserContextEventGuard,
    revalidationOutcome,
    USER_CONTEXT_EVENT,
    userContextChannel,
} from '@/lib/user-context';
import { login, workspace } from '@/routes';

/**
 * Keeps an open session in step with the account's identity and access. On `user.context_changed` (or after the
 * realtime connection recovers, since signals may have been missed) the current page is revalidated with one
 * debounced reload: the server re-authorizes the URL and returns fresh shared props, so the sidebar, Role label,
 * Position, picture and Branch selector update in place. If the page is no longer allowed, the account goes to its
 * workspace (the server picks the landing page or Branch picker); if the session ended, to login. No polling.
 */
export function useUserContextRealtime(userId: number): void {
    const connectionStatus = useConnectionStatus();
    const previousStatus = useRef(connectionStatus);
    const hasConnected = useRef(connectionStatus === 'connected');

    const refresh = useMemo(
        () =>
            createRealtimeRefresh((onFinish) => {
                let cancel: (() => void) | undefined;
                router.reload({
                    onCancelToken: (token) => {
                        cancel = token.cancel;
                    },
                    onHttpException: (response) => {
                        const outcome = revalidationOutcome(response.status);
                        if (outcome === 'login') {
                            window.location.assign(login.url());
                        } else if (outcome === 'workspace') {
                            router.visit(workspace.url(), { replace: true });
                        }

                        return false;
                    },
                    onNetworkError: () => false,
                    onFinish,
                });

                return () => cancel?.();
            }, 300),
        [],
    );
    const scheduleRefresh = refresh.schedule;
    const acceptEvent = useMemo(
        () => createUserContextEventGuard(userId),
        [userId],
    );

    useEcho<Record<string, unknown>>(
        userContextChannel(userId),
        [USER_CONTEXT_EVENT],
        (event) => {
            if (acceptEvent(event)) {
                scheduleRefresh();
            }
        },
        [acceptEvent, scheduleRefresh],
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
        let navigations = 0;
        const removeStart = router.on('start', (event) => {
            if (!event.detail.visit.async) {
                navigations += 1;
                refresh.hold();
            }
        });
        const removeFinish = router.on('finish', (event) => {
            if (!event.detail.visit.async) {
                navigations = Math.max(0, navigations - 1);
                if (navigations === 0) {
                    refresh.release();
                }
            }
        });

        return () => {
            refresh.dispose();
            removeStart();
            removeFinish();
        };
    }, [refresh]);
}

/** Mounted once per signed-in layout; renders nothing. */
export function UserContextRealtime({ userId }: { userId: number }) {
    useUserContextRealtime(userId);

    return null;
}
