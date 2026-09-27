import Echo from 'laravel-echo';
import Pusher from 'pusher-js';
import { qrRequest } from '@/lib/qr-http';

/**
 * One Reverb connection for a public page that has no staff session (the customer screen, the pickup page), like the
 * Customer QR page's own client. Private channels are authorized by the page's own capability (device cookie or pickup
 * token) through its dedicated endpoint; the staff app's Echo configuration is never used here. Returns null when
 * realtime is not configured, so the page shows "Live updates unavailable" and still works by refetching.
 */
export function createPublicEcho(authUrl: string): {
    echo: Echo<'reverb'>;
    client: Pusher;
} | null {
    const key = import.meta.env.VITE_REVERB_APP_KEY;
    if (!key) {
        return null;
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
                qrRequest<{ auth: string }>(
                    { url: authUrl, method: 'post' },
                    {
                        socket_id: params.socketId,
                        channel_name: params.channelName,
                    },
                )
                    .then((result) => callback(null, result))
                    .catch(() =>
                        callback(
                            new Error('Live update authorization failed'),
                            null,
                        ),
                    );
            },
        },
    });

    return {
        echo: new Echo<'reverb'>({ broadcaster: 'reverb', client }),
        client,
    };
}
