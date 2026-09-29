import { useEffect, useId, useSyncExternalStore } from 'react';
import type { ConnectivitySnapshot } from '@/lib/pwa-connectivity';
import {
    pwaRuntime,
    type PwaInstallSnapshot,
    type PwaPushSnapshot,
    type PwaUiSnapshot,
} from '@/lib/pwa-runtime';
import type { UpdateSnapshot } from '@/lib/pwa-update';

/** Server rendering and the first hydration pass see a quiet, inactive app; the browser then takes over. */
const SERVER_UI: PwaUiSnapshot = {
    active: false,
    statusSlots: 0,
    appDialogOpen: false,
};
const SERVER_CONNECTIVITY: ConnectivitySnapshot = {
    phase: 'online',
    lastSyncedAt: null,
};
const SERVER_UPDATE: UpdateSnapshot = {
    available: false,
    source: null,
    applying: false,
    snoozedUntil: null,
    blockers: [],
};
const SERVER_INSTALL: PwaInstallSnapshot = {
    state: 'browser-menu',
    platform: 'other',
};
const SERVER_PUSH: PwaPushSnapshot = {
    state: 'unsupported',
    support: 'unsupported',
    checked: false,
    busy: false,
};
const never = () => () => undefined;

export function usePwaUi(): PwaUiSnapshot {
    const runtime = pwaRuntime();

    return useSyncExternalStore(
        runtime?.ui.subscribe ?? never,
        runtime?.ui.get ?? (() => SERVER_UI),
        () => SERVER_UI,
    );
}

export function usePwaConnectivity(): ConnectivitySnapshot {
    const runtime = pwaRuntime();

    return useSyncExternalStore(
        runtime?.connectivity.subscribe ?? never,
        runtime?.connectivity.getSnapshot ?? (() => SERVER_CONNECTIVITY),
        () => SERVER_CONNECTIVITY,
    );
}

export function usePwaUpdate(): UpdateSnapshot {
    const runtime = pwaRuntime();

    return useSyncExternalStore(
        runtime?.update.subscribe ?? never,
        runtime?.update.getSnapshot ?? (() => SERVER_UPDATE),
        () => SERVER_UPDATE,
    );
}

export function usePwaInstall(): PwaInstallSnapshot {
    const runtime = pwaRuntime();

    return useSyncExternalStore(
        runtime?.install.subscribe ?? never,
        runtime?.install.get ?? (() => SERVER_INSTALL),
        () => SERVER_INSTALL,
    );
}

export function usePwaPush(): PwaPushSnapshot {
    const runtime = pwaRuntime();

    return useSyncExternalStore(
        runtime?.push.subscribe ?? never,
        runtime?.push.get ?? (() => SERVER_PUSH),
        () => SERVER_PUSH,
    );
}

/**
 * Hold app updates (and notification-tap navigation) while this screen has work a reload would destroy. The reason is
 * shown to the user; the new version simply waits until the screen is safe again.
 */
export function useUpdateBlocker(active: boolean, reason: string): void {
    const id = useId();

    useEffect(() => {
        const runtime = pwaRuntime();
        runtime?.update.setBlocker(id, active ? reason : null);

        return () => runtime?.update.setBlocker(id, null);
    }, [id, active, reason]);
}

/** A header that shows the status inline; while one is mounted the floating fallback stays hidden. */
export function usePwaStatusSlot(enabled: boolean): void {
    useEffect(() => {
        const runtime = pwaRuntime();
        if (!runtime || !enabled) {
            return;
        }
        runtime.ui.set({ statusSlots: runtime.ui.get().statusSlots + 1 });

        return () =>
            runtime.ui.set({ statusSlots: runtime.ui.get().statusSlots - 1 });
    }, [enabled]);
}

export function openPwaAppDialog(): void {
    pwaRuntime()?.ui.set({ appDialogOpen: true });
}
