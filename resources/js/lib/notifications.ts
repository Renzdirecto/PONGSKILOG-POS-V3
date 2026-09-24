export type AdminNotification = {
    id: string;
    category: string;
    title: string;
    body: string;
    url: string | null;
    read: boolean;
    created_at: string | null;
};

export type NotificationCenter = { unread: number } | null;

export const NOTIFICATION_CATEGORY_LABELS: Record<string, string> = {
    access: 'Access',
    staff: 'Staff',
    stock: 'Stock',
};

export function notificationCategoryLabel(category: string): string {
    return NOTIFICATION_CATEGORY_LABELS[category] ?? 'Notice';
}

/** The badge shows the real count; large counts are capped visually, and zero shows no badge at all. */
export function unreadBadgeLabel(
    unread: number | null | undefined,
): string | null {
    if (
        unread === null ||
        unread === undefined ||
        !Number.isFinite(unread) ||
        unread <= 0
    ) {
        return null;
    }

    return unread > 99 ? '99+' : String(Math.floor(unread));
}

/** Accessible name for the header bell, including the real unread count. */
export function notificationBellLabel(
    unread: number | null | undefined,
): string {
    const label = unreadBadgeLabel(unread);

    return label === null
        ? 'Notifications, no unread'
        : `Notifications, ${label} unread`;
}

/** Only same-app relative links produced by the server are followed; anything else is ignored. */
export function isSafeNotificationUrl(
    url: string | null | undefined,
): url is string {
    return (
        typeof url === 'string' &&
        url.startsWith('/') &&
        !url.startsWith('//') &&
        !url.includes('\\')
    );
}
