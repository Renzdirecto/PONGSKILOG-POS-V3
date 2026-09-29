import { useSyncExternalStore } from 'react';

/**
 * Appearance (Phase 20): Light or Dark, chosen per device, default Light. There is no "system" mode: a device follows
 * what its staff member chose, never the OS. Customer-facing screens are always Light (`setAppearanceLock`).
 */
export type Appearance = 'light' | 'dark';
export type ResolvedAppearance = Appearance;

export type UseAppearanceReturn = {
    readonly appearance: Appearance;
    readonly resolvedAppearance: ResolvedAppearance;
    readonly updateAppearance: (mode: Appearance) => void;
};

const STORAGE_KEY = 'appearance';
const listeners = new Set<() => void>();
let currentAppearance: Appearance = 'light';
let lockedLight = false;

const setCookie = (name: string, value: string, days = 365): void => {
    if (typeof document === 'undefined') {
        return;
    }

    const maxAge = days * 24 * 60 * 60;
    document.cookie = `${name}=${value};path=/;max-age=${maxAge};SameSite=Lax`;
};

/** A stored legacy "system" (or anything unknown) is Light; storage may be unavailable in private modes. */
const readStoredAppearance = (): Appearance => {
    try {
        return window.localStorage.getItem(STORAGE_KEY) === 'dark'
            ? 'dark'
            : 'light';
    } catch {
        return 'light';
    }
};

const storeAppearance = (mode: Appearance): void => {
    try {
        window.localStorage.setItem(STORAGE_KEY, mode);
    } catch {
        // The cookie below still carries the choice to the server.
    }
    setCookie(STORAGE_KEY, mode);
};

const applyTheme = (): void => {
    if (typeof document === 'undefined') {
        return;
    }
    const dark = !lockedLight && currentAppearance === 'dark';

    document.documentElement.classList.toggle('dark', dark);
    document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
};

const subscribe = (callback: () => void) => {
    listeners.add(callback);

    return () => listeners.delete(callback);
};

const notify = (): void => listeners.forEach((listener) => listener());

export function setAppearanceLock(locked: boolean): void {
    if (lockedLight !== locked) {
        lockedLight = locked;
        applyTheme();
    }
}

export function initializeTheme(): void {
    if (typeof window === 'undefined') {
        return;
    }

    lockedLight = document.documentElement.dataset.appearanceLock === 'light';
    currentAppearance = readStoredAppearance();
    applyTheme();
}

export function useAppearance(): UseAppearanceReturn {
    const appearance: Appearance = useSyncExternalStore(
        subscribe,
        () => currentAppearance,
        () => 'light',
    );

    const updateAppearance = (mode: Appearance): void => {
        currentAppearance = mode;
        storeAppearance(mode);
        applyTheme();
        notify();
    };

    return {
        appearance,
        resolvedAppearance: appearance,
        updateAppearance,
    } as const;
}
