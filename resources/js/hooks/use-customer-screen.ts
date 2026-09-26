import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import {
    media as mediaRoute,
    menu as menuRoute,
    pairingCode as pairingCodeRoute,
    state as stateRoute,
} from '@/routes/customer-screen';
import { auth as authorizeChannel } from '@/routes/customer-screen/broadcasting';
import {
    refreshDelayMs,
    type CustomerMenuData,
    type CustomerScreenPlaylist,
    type CustomerScreenState,
} from '@/lib/customer-screen';
import { createPublicEcho } from '@/lib/public-echo';
import { qrRequest } from '@/lib/qr-http';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';

type ScreenEvent = { event_id?: string; reason?: string };

/**
 * The customer screen's live state. Every realtime event is only an invalidation: the screen refetches its one
 * authoritative projection (one request at a time, the last one wins, so a slow response never lands over a newer
 * one), the Menu and the advertisement playlist. After a reconnect, a returning network or the tablet waking up, it
 * refetches everything once. There is no polling: the only timers renew a pairing code or signed media links just
 * before they expire.
 */
export function useCustomerScreen(initial: CustomerScreenState) {
    const [screen, setScreen] = useState(initial);
    const [connection, setConnection] = useState<string>('connecting');
    const [pairingCode, setPairingCode] = useState<{
        code: string;
        expires_at: string;
    } | null>(null);
    const [menu, setMenu] = useState<CustomerMenuData | null>(null);
    const [playlist, setPlaylist] = useState<CustomerScreenPlaylist | null>(
        null,
    );
    const modeRef = useRef(screen.mode);
    modeRef.current = screen.mode;
    const paired = screen.status === 'paired';

    const stateRefresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                qrRequest<{ screen: CustomerScreenState }>(stateRoute())
                    .then((result) => setScreen(result.screen))
                    .catch(() => undefined)
                    .finally(finish);
            }, 60),
        [],
    );
    const menuRefresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                qrRequest<{ menu: CustomerMenuData }>(menuRoute())
                    .then((result) => setMenu(result.menu))
                    .catch(() => undefined)
                    .finally(finish);
            }, 400),
        [],
    );
    const mediaRefresh = useMemo(
        () =>
            createRealtimeRefresh((finish) => {
                qrRequest<{ media: CustomerScreenPlaylist }>(mediaRoute())
                    .then((result) => setPlaylist(result.media))
                    .catch(() => undefined)
                    .finally(finish);
            }, 200),
        [],
    );

    const refreshAll = useCallback(() => {
        stateRefresh.schedule(0);
        if (modeRef.current === 'menu') {
            menuRefresh.schedule(0);
        }
        mediaRefresh.schedule(0);
    }, [stateRefresh, menuRefresh, mediaRefresh]);

    useEffect(() => {
        for (const refresh of [stateRefresh, menuRefresh, mediaRefresh]) {
            refresh.activate();
        }

        return () => {
            for (const refresh of [stateRefresh, menuRefresh, mediaRefresh]) {
                refresh.dispose();
            }
        };
    }, [stateRefresh, menuRefresh, mediaRefresh]);

    /** Paired screens load their playlist; the Menu is loaded when Menu mode is (or becomes) selected. */
    useEffect(() => {
        if (paired) {
            mediaRefresh.schedule(0);
        } else {
            setPlaylist(null);
            setMenu(null);
        }
    }, [paired, mediaRefresh]);
    useEffect(() => {
        if (paired && screen.mode === 'menu') {
            menuRefresh.schedule(0);
        }
    }, [paired, screen.mode, menuRefresh]);

    /** Signed media and Menu image links are renewed a few minutes before they expire. */
    useEffect(() => {
        if (!playlist) return;
        const timer = window.setTimeout(
            () => mediaRefresh.schedule(0),
            refreshDelayMs(playlist.expires_at),
        );

        return () => window.clearTimeout(timer);
    }, [playlist, mediaRefresh]);
    useEffect(() => {
        if (!menu) return;
        const timer = window.setTimeout(
            () => menuRefresh.schedule(0),
            refreshDelayMs(menu.expires_at),
        );

        return () => window.clearTimeout(timer);
    }, [menu, menuRefresh]);

    /**
     * An unpaired screen shows a fresh one-time code, renewed when it expires. Each request replaces the previous code
     * on the server, so only the newest request's answer is ever shown; a failed request is retried after 10 seconds.
     */
    const codeRequest = useRef(0);
    const [codeRetry, setCodeRetry] = useState(0);
    const requestCode = useCallback(() => {
        const request = ++codeRequest.current;
        qrRequest<{
            code: string | null;
            expires_at: string | null;
            screen: CustomerScreenState;
        }>(pairingCodeRoute())
            .then((result) => {
                if (request !== codeRequest.current) return;
                setScreen(result.screen);
                setPairingCode(
                    result.code && result.expires_at
                        ? { code: result.code, expires_at: result.expires_at }
                        : null,
                );
            })
            .catch(() => {
                if (request !== codeRequest.current) return;
                setPairingCode(null);
                window.setTimeout(
                    () => setCodeRetry((attempt) => attempt + 1),
                    10_000,
                );
            });
    }, []);
    useEffect(() => {
        if (paired) {
            codeRequest.current += 1;
            setPairingCode(null);

            return;
        }
        if (pairingCode === null) {
            requestCode();

            return;
        }
        const timer = window.setTimeout(
            requestCode,
            Math.max(
                1000,
                new Date(pairingCode.expires_at).getTime() - Date.now(),
            ),
        );

        return () => window.clearTimeout(timer);
    }, [paired, pairingCode, requestCode, codeRetry]);

    /** One public Reverb client for the page's lifetime. */
    const connectionRef = useRef<ReturnType<typeof createPublicEcho>>(null);
    useEffect(() => {
        const live = createPublicEcho(authorizeChannel.url());
        connectionRef.current = live;
        if (live === null) {
            setConnection('unavailable');

            return;
        }
        let hasConnected = false;
        live.client.connection.bind(
            'state_change',
            ({ current }: { current: string }) => {
                setConnection(current);
                if (current === 'connected') {
                    if (hasConnected) {
                        refreshAll();
                    }
                    hasConnected = true;
                }
            },
        );
        const wake = () => {
            if (document.visibilityState === 'visible') {
                refreshAll();
            }
        };
        window.addEventListener('online', refreshAll);
        document.addEventListener('visibilitychange', wake);

        return () => {
            window.removeEventListener('online', refreshAll);
            document.removeEventListener('visibilitychange', wake);
            live.echo.disconnect();
            connectionRef.current = null;
        };
    }, [refreshAll]);

    /** Subscriptions follow the channels the server authorizes for the current pairing. */
    const screenChannel = screen.channels?.screen ?? null;
    const catalogChannel = screen.channels?.catalog ?? null;
    const boardChannel = screen.channels?.board ?? null;
    useEffect(() => {
        const live = connectionRef.current;
        if (live === null || screenChannel === null) return;
        const seen = new Set<string>();
        const fresh = (event: ScreenEvent) => {
            if (typeof event.event_id !== 'string') return true;
            if (seen.has(event.event_id)) return false;
            seen.add(event.event_id);
            if (seen.size > 256) seen.delete(seen.values().next().value!);

            return true;
        };
        live.echo
            .private(screenChannel)
            .listen('.customer_screen.changed', (event: ScreenEvent) => {
                if (!fresh(event)) return;
                stateRefresh.schedule();
                if (event.reason === 'ads' || event.reason === 'pairing') {
                    mediaRefresh.schedule();
                }
            });
        if (catalogChannel) {
            live.echo
                .private(catalogChannel)
                .listen('.qr.catalog_changed', (event: ScreenEvent) => {
                    if (fresh(event) && modeRef.current === 'menu') {
                        menuRefresh.schedule();
                    }
                });
        }
        if (boardChannel) {
            live.echo
                .private(boardChannel)
                .listen('.display.orders_changed', (event: ScreenEvent) => {
                    if (
                        fresh(event) &&
                        modeRef.current === 'customer_display'
                    ) {
                        stateRefresh.schedule();
                    }
                });
        }

        return () => {
            live.echo.leave(screenChannel);
            if (catalogChannel) live.echo.leave(catalogChannel);
            if (boardChannel) live.echo.leave(boardChannel);
        };
    }, [
        screenChannel,
        catalogChannel,
        boardChannel,
        stateRefresh,
        menuRefresh,
        mediaRefresh,
    ]);

    return {
        screen,
        connection,
        pairingCode,
        menu,
        playlist,
        refresh: () => stateRefresh.schedule(0),
    };
}
