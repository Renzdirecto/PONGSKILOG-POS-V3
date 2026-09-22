import { router } from '@inertiajs/react';
import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { useEffect, useState } from 'react';
import { auth } from '@/routes/qr/broadcasting';
import { qrRequest } from '@/lib/qr-http';
import { createRealtimeRefresh } from '@/lib/realtime-refresh';

export function useCustomerQrRealtime(branchId: string, trackingId?: string) {
    const [status, setStatus] = useState('connecting');
    useEffect(() => {
        const refresh = createRealtimeRefresh(
            (finish) =>
                router.reload({
                    only: ['catalog', 'order', 'store'],
                    onFinish: finish,
                }),
            35,
        );
        const online = () => refresh.schedule(0);
        window.addEventListener('online', online);
        window.addEventListener('focus', online);
        const key = import.meta.env.VITE_REVERB_APP_KEY;
        if (!key) {
            setStatus('unavailable');
            return () => {
                refresh.dispose();
                window.removeEventListener('online', online);
                window.removeEventListener('focus', online);
            };
        }
        const client = new Pusher(key, {
            cluster: '',
            wsHost: import.meta.env.VITE_REVERB_HOST,
            wsPort: Number(import.meta.env.VITE_REVERB_PORT || 80),
            wssPort: Number(import.meta.env.VITE_REVERB_PORT || 443),
            forceTLS: import.meta.env.VITE_REVERB_SCHEME === 'https',
            enabledTransports: ['ws', 'wss'],
            channelAuthorization: {
                customHandler: (params, callback) => {
                    qrRequest<{ auth: string }>(auth(branchId), {
                        socket_id: params.socketId,
                        channel_name: params.channelName,
                    })
                        .then((result) => callback(null, result))
                        .catch(() =>
                            callback(
                                new Error(
                                    'Ordering session authorization failed',
                                ),
                                null,
                            ),
                        );
                },
            },
        });
        const echo = new Echo<'reverb'>({ broadcaster: 'reverb', client });
        const seen = new Set<string>();
        const invalidate = (event: {
            event_id: string;
            branch_id?: string;
            public_tracking_id?: string;
        }) => {
            if (event.branch_id && event.branch_id !== branchId) return;
            if (
                event.public_tracking_id &&
                event.public_tracking_id !== trackingId
            )
                return;
            if (seen.has(event.event_id)) return;
            seen.add(event.event_id);
            if (seen.size > 512) seen.delete(seen.values().next().value!);
            refresh.schedule();
        };
        echo.private(`qr-catalog.${branchId}`).listen(
            '.qr.catalog_changed',
            invalidate,
        );
        if (trackingId)
            echo.private(`order-tracking.${trackingId}`).listen(
                '.order.tracking_changed',
                invalidate,
            );
        client.connection.bind(
            'state_change',
            ({ current }: { current: string }) => {
                setStatus(current);
                if (current === 'connected') refresh.schedule(0);
            },
        );
        return () => {
            refresh.dispose();
            echo.disconnect();
            window.removeEventListener('online', online);
            window.removeEventListener('focus', online);
        };
    }, [branchId, trackingId]);
    return status;
}
