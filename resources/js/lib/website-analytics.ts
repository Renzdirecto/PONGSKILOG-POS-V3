/** Events the public landing page reports; a saved review is counted by the server. */
export type WebsiteEventName =
    | 'page_view'
    | 'facebook_click'
    | 'directions_click'
    | 'menu_click';

/** The landing link a visitor followed, when it is one the business counts. */
export function websiteLinkEvent(href: string | null): WebsiteEventName | null {
    if (!href) return null;
    if (href.includes('facebook.com/')) return 'facebook_click';
    if (href.includes('maps.app.goo.gl/')) return 'directions_click';
    return href === '#board' ? 'menu_click' : null;
}

/** At most one event of a kind per second, so a double tap or a development double-mount is counted once. */
export function websiteEventDue(
    sent: Partial<Record<WebsiteEventName, number>>,
    event: WebsiteEventName,
    now: number,
): boolean {
    if (now - (sent[event] ?? -Infinity) < 1000) return false;
    sent[event] = now;
    return true;
}

export type WebsitePeriod = 'today' | 'last_7_days' | 'last_30_days' | 'custom';

/** Mirrors `WebsiteAnalytics::PERIODS`. */
export const WEBSITE_PERIODS: readonly [WebsitePeriod, string][] = [
    ['today', 'Today'],
    ['last_7_days', '7 Days'],
    ['last_30_days', '30 Days'],
    ['custom', 'Custom'],
];

export type WebsiteSeriesBucket = {
    key: string;
    label: string;
    full: string;
    page_views: number;
    visitors: number;
    heart_taps: number;
};

/** The tabs of the Owner's Website workspace, in order; an account sees only the ones it may use. */
export type WebsiteTab = 'analytics' | 'reviews' | 'team' | 'shippy';
export const WEBSITE_TABS: readonly [WebsiteTab, string][] = [
    ['analytics', 'Analytics'],
    ['reviews', 'Reviews'],
    ['team', 'Team'],
    ['shippy', 'Shippy'],
];
export function websiteTabs(
    can: Record<WebsiteTab, boolean>,
): readonly [WebsiteTab, string][] {
    return WEBSITE_TABS.filter(([tab]) => can[tab]);
}

export type WebsiteReport = {
    refreshed_at: string;
    period: {
        preset: WebsitePeriod;
        from: string;
        to: string;
        label: string;
        days: number;
        granularity: 'hour' | 'day';
    };
    totals: {
        page_views: number;
        visitors: number;
        facebook_clicks: number;
        directions_clicks: number;
        menu_clicks: number;
        review_submissions: number;
        /** Tap-to-love taps saved in the period. */
        heart_taps: number;
    };
    /** Every tap ever saved: the number the landing page shows beside the chef. */
    heart_taps_all_time: number;
    series: WebsiteSeriesBucket[];
    tracking_since: string | null;
};

export function websiteRefreshLabel(timestamp: string): string {
    return new Intl.DateTimeFormat('en-PH', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        second: '2-digit',
        hour12: true,
    }).format(new Date(timestamp));
}

/** The shareable query of a period: Today is the default and only Custom carries a range. */
export function websiteAnalyticsQuery(
    period: WebsitePeriod,
    from?: string,
    to?: string,
): Record<string, string> {
    if (period === 'today') return {};
    return period === 'custom' && from && to
        ? { period, from, to }
        : { period };
}
