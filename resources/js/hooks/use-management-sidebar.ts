import { usePage } from '@inertiajs/react';
import { useCallback, useState } from 'react';
import { sidebarPreferenceCookie } from '@/lib/management-navigation';

/** The collapsible desktop sidebar: starts from the server-read preference and remembers each toggle on this device. */
export function useManagementSidebar(): [boolean, () => void] {
    const { sidebarOpen } = usePage<{ sidebarOpen?: boolean }>().props;
    const [collapsed, setCollapsed] = useState(sidebarOpen === false);
    const toggle = useCallback(() => {
        setCollapsed((current) => {
            const next = !current;
            try {
                document.cookie = sidebarPreferenceCookie(next);
            } catch {
                /** Cookies can be blocked; the toggle still works for this page. */
            }
            return next;
        });
    }, []);

    return [collapsed, toggle];
}
