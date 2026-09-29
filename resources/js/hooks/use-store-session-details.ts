import { createContext, useContext } from 'react';

/**
 * Opens the operational layout's existing Current Store Session dialog, so pages
 * reuse its authoritative fetch, expenses and realtime refresh instead of duplicating them.
 */
export const StoreSessionDetailsContext = createContext<
    (() => void) | null
>(null);

export function useStoreSessionDetails(): (() => void) | null {
    return useContext(StoreSessionDetailsContext);
}
