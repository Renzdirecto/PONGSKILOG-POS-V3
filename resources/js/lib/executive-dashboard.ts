/** An actionable item computed by the server from real state (stock, Stores, notifications). */
export type AttentionItem = {
    key: string;
    tone: 'critical' | 'warning' | 'notice' | 'security';
    title: string;
    detail: string;
    href: string;
};

export type ExecutiveStore = {
    branch: { id: string; name: string; code: string };
    open: boolean;
    opened_at: string | null;
    opened_by: string | null;
};

export type ExecutivePeople = {
    active: number;
    inactive: number;
    super_admins: number;
    custom_roles: number;
};

/** One Audit summary row: no before/after payload ever reaches the dashboard. */
export type ExecutiveAudit = {
    id: string;
    action: string;
    module: string;
    actor: string | null;
    branch: string | null;
    at: string | null;
};

/**
 * Semantic colours: red only for something that cannot be sold, amber for low stock, neutral for a closed Store and
 * violet for Control Center security items.
 */
export const ATTENTION_TONES: Record<
    AttentionItem['tone'],
    { label: string; panel: string; icon: string }
> = {
    critical: {
        label: 'Critical',
        panel: 'border-red-200 bg-red-50 text-red-900 hover:border-red-400',
        icon: 'bg-red-100 text-red-700',
    },
    warning: {
        label: 'Warning',
        panel: 'border-amber-200 bg-amber-50 text-amber-950 hover:border-amber-400',
        icon: 'bg-amber-100 text-amber-800',
    },
    notice: {
        label: 'Notice',
        panel: 'border-[#e5e5e5] bg-white text-[#111] hover:border-[#111]',
        icon: 'bg-[#f2f2f2] text-[#555]',
    },
    security: {
        label: 'Security',
        panel: 'border-violet-200 bg-violet-50 text-violet-950 hover:border-violet-400',
        icon: 'bg-violet-100 text-violet-700',
    },
};

/** "Open since 8:05 AM · Juan" or "No open Store Session", in Philippine time. */
export function storeOpenLabel(store: ExecutiveStore): string {
    if (!store.open || store.opened_at === null) {
        return 'No open Store Session';
    }
    const time = new Date(store.opened_at).toLocaleTimeString('en-PH', {
        timeZone: 'Asia/Manila',
        hour: 'numeric',
        minute: '2-digit',
    });

    return `Open since ${time}${store.opened_by ? ` · ${store.opened_by}` : ''}`;
}
