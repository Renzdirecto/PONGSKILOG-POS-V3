import { useLayoutEffect } from 'react';
import { setAppearanceLock } from '@/hooks/use-appearance';
import { isCustomerFacingPage } from '@/lib/appearance';

/**
 * Customer-facing pages keep their designed Light look, also after a client-side visit from a staff page on a device
 * that uses Dark (the server locks them on a full page load).
 */
export function AppearanceLock({ component }: { component: string }) {
    useLayoutEffect(() => {
        setAppearanceLock(isCustomerFacingPage(component));
    }, [component]);

    return null;
}
