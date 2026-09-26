import { useEffect, useRef, useState } from 'react';
import { PwaAppDialog } from '@/components/pwa-app-dialog';
import { PwaFloatingStatus } from '@/components/pwa-status';
import { usePwaConnectivity, usePwaUi } from '@/hooks/use-pwa';
import type { ConnectivityPhase } from '@/lib/pwa-connectivity';
import { pwaRuntime } from '@/lib/pwa-runtime';

const ANNOUNCEMENTS: Record<ConnectivityPhase, string> = {
    offline:
        "You're offline. This screen may be out of date; changes need internet.",
    reconnecting: 'Reconnecting to PONGSKILOG.',
    online: 'Back online. PONGSKILOG is up to date.',
};

/** Announces connectivity changes to screen readers (never the initial state). */
function PwaAnnouncer() {
    const { phase } = usePwaConnectivity();
    const previous = useRef<ConnectivityPhase>(phase);
    const [message, setMessage] = useState('');

    useEffect(() => {
        if (previous.current !== phase) {
            previous.current = phase;
            setMessage(ANNOUNCEMENTS[phase]);
        }
    }, [phase]);

    return (
        <div role="status" aria-live="polite" className="sr-only">
            {message}
        </div>
    );
}

/**
 * Mounted once beside the app. Removes the installed-app startup screen, starts the PWA runtime for staff surfaces
 * (never on public Customer QR or receipt pages) and renders the shared status fallback and app panel.
 */
export function PwaRuntime({
    component,
    signedIn,
}: {
    component: string;
    signedIn: boolean;
}) {
    const ui = usePwaUi();

    useEffect(() => {
        document.getElementById('pwa-boot')?.remove();
        pwaRuntime()?.start({ component, signedIn });
    }, [component, signedIn]);

    if (!ui.active) {
        return null;
    }

    return (
        <>
            <PwaAnnouncer />
            <PwaFloatingStatus />
            <PwaAppDialog />
        </>
    );
}
