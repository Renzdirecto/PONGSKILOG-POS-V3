import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Bell,
    CheckCheck,
    ChevronLeft,
    ChevronRight,
    PackageX,
    ShieldCheck,
    UserCog,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerPanelClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import { Spinner } from '@/components/ui/spinner';
import { useNotificationsPageRefresh } from '@/hooks/use-notification-center';
import {
    isSafeNotificationUrl,
    notificationCategoryLabel,
    type AdminNotification,
} from '@/lib/notifications';
import { notifications as notificationsRoute } from '@/routes/super-admin';
import { read, readAll } from '@/routes/super-admin/notifications';
import type { Auth } from '@/types';

type Props = {
    notifications: {
        data: AdminNotification[];
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    unreadCount: number;
    filter: 'all' | 'unread';
};

const dateTime = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

const categoryIcons: Record<string, { icon: LucideIcon; className: string }> = {
    access: { icon: ShieldCheck, className: 'bg-violet-50 text-violet-700' },
    staff: { icon: UserCog, className: 'bg-blue-50 text-blue-700' },
    stock: { icon: PackageX, className: 'bg-red-50 text-red-700' },
};

export default function Notifications({
    notifications,
    unreadCount,
    filter,
}: Props) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const [busy, setBusy] = useState<string | null>(null);

    useNotificationsPageRefresh(auth.user?.id ?? 0);

    function markAllRead() {
        setBusy('all');
        router.post(
            readAll.url(),
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    }

    function open(notification: AdminNotification) {
        setBusy(notification.id);
        router.post(
            read.url(notification.id),
            { open: isSafeNotificationUrl(notification.url) },
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    }

    function markRead(notification: AdminNotification) {
        setBusy(notification.id);
        router.post(
            read.url(notification.id),
            {},
            { preserveScroll: true, onFinish: () => setBusy(null) },
        );
    }

    return (
        <>
            <Head title="Notifications" />
            <OwnerPage
                title="Notifications"
                description="Security and stock alerts for the Control Center. Only real events appear here."
                maxWidth="max-w-[880px]"
                action={
                    <button
                        type="button"
                        onClick={markAllRead}
                        disabled={unreadCount === 0 || busy !== null}
                        className={`${ownerSecondaryActionClass} inline-flex w-full items-center justify-center gap-2 disabled:cursor-not-allowed disabled:opacity-50 md:w-auto`}
                    >
                        {busy === 'all' ? (
                            <Spinner />
                        ) : (
                            <CheckCheck className="size-4" aria-hidden="true" />
                        )}
                        Mark all read
                    </button>
                }
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <p role="status" className="text-[12.5px] text-[#666]">
                        {unreadCount === 0
                            ? 'No unread notifications'
                            : `${unreadCount} unread`}
                    </p>
                    <div
                        role="group"
                        aria-label="Show notifications"
                        className="flex rounded-[10px] bg-[#ededed] p-1"
                    >
                        {(
                            [
                                ['all', 'All'],
                                ['unread', 'Unread'],
                            ] as const
                        ).map(([value, label]) => (
                            <Link
                                key={value}
                                href={notificationsRoute({
                                    query:
                                        value === 'all'
                                            ? {}
                                            : { filter: value },
                                })}
                                preserveScroll
                                aria-current={
                                    filter === value ? 'true' : undefined
                                }
                                className={`flex min-h-11 min-w-20 items-center justify-center rounded-lg px-3 text-[12.5px] font-semibold ${filter === value ? 'bg-white text-[#111] shadow-sm' : 'text-[#666]'}`}
                            >
                                {label}
                            </Link>
                        ))}
                    </div>
                </div>

                {notifications.data.length === 0 ? (
                    <div
                        className={`${ownerPanelClass} px-5 py-12 text-center`}
                    >
                        <Bell
                            className="mx-auto size-7 text-[#aaa]"
                            aria-hidden="true"
                        />
                        <h2 className="mt-3 text-sm font-semibold">
                            {filter === 'unread'
                                ? 'You are all caught up'
                                : 'No notifications yet'}
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[#767676]">
                            Staff and access changes by other Super Admins and
                            products or ingredients running out will appear
                            here.
                        </p>
                    </div>
                ) : (
                    <ul
                        aria-label="Notifications"
                        className={`${ownerPanelClass} divide-y divide-[#f0f0f0] overflow-hidden`}
                    >
                        {notifications.data.map((notification) => {
                            const style = categoryIcons[
                                notification.category
                            ] ?? {
                                icon: Bell,
                                className: 'bg-neutral-100 text-neutral-700',
                            };
                            const Icon = style.icon;
                            const canOpen = isSafeNotificationUrl(
                                notification.url,
                            );

                            return (
                                <li
                                    key={notification.id}
                                    className={`flex gap-3 p-4 ${notification.read ? '' : 'bg-[#fbfbfb]'}`}
                                >
                                    <span
                                        className={`flex size-10 shrink-0 items-center justify-center rounded-xl ${style.className}`}
                                    >
                                        <Icon
                                            className="size-[18px]"
                                            aria-hidden="true"
                                        />
                                    </span>
                                    <div className="min-w-0 flex-1">
                                        <div className="flex flex-wrap items-center gap-1.5">
                                            <OwnerStatusBadge tone="outline">
                                                {notificationCategoryLabel(
                                                    notification.category,
                                                )}
                                            </OwnerStatusBadge>
                                            {!notification.read && (
                                                <OwnerStatusBadge tone="red">
                                                    Unread
                                                </OwnerStatusBadge>
                                            )}
                                        </div>
                                        <h2
                                            className={`mt-1.5 text-[13.5px] wrap-break-word ${notification.read ? 'font-medium' : 'font-semibold'}`}
                                        >
                                            {notification.title}
                                        </h2>
                                        <p className="mt-0.5 text-[12.5px] leading-5 wrap-break-word text-[#555]">
                                            {notification.body}
                                        </p>
                                        <p className="mt-1 text-[11px] text-[#888]">
                                            {notification.created_at
                                                ? dateTime.format(
                                                      new Date(
                                                          notification.created_at,
                                                      ),
                                                  )
                                                : 'Time unavailable'}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {canOpen && (
                                                <button
                                                    type="button"
                                                    disabled={busy !== null}
                                                    onClick={() =>
                                                        open(notification)
                                                    }
                                                    className={`${ownerSecondaryActionClass} inline-flex items-center gap-1.5`}
                                                >
                                                    {busy ===
                                                        notification.id && (
                                                        <Spinner />
                                                    )}
                                                    Open
                                                </button>
                                            )}
                                            {!notification.read && (
                                                <button
                                                    type="button"
                                                    disabled={busy !== null}
                                                    onClick={() =>
                                                        markRead(notification)
                                                    }
                                                    className={
                                                        ownerSecondaryActionClass
                                                    }
                                                    aria-label={`Mark "${notification.title}" as read`}
                                                >
                                                    Mark read
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}

                {(notifications.prev_page_url ||
                    notifications.next_page_url) && (
                    <nav
                        aria-label="Notification pages"
                        className={`${ownerPanelClass} flex flex-wrap items-center justify-between gap-2 px-4 py-3 text-[12px] text-[#767676]`}
                    >
                        <span>
                            {notifications.from}–{notifications.to} of{' '}
                            {notifications.total}
                        </span>
                        <span className="flex gap-2">
                            {notifications.prev_page_url && (
                                <Link
                                    href={notifications.prev_page_url}
                                    preserveScroll
                                    className={`${ownerSecondaryActionClass} inline-flex items-center gap-1`}
                                >
                                    <ChevronLeft className="size-4" />
                                    Newer
                                </Link>
                            )}
                            {notifications.next_page_url && (
                                <Link
                                    href={notifications.next_page_url}
                                    preserveScroll
                                    className={`${ownerSecondaryActionClass} inline-flex items-center gap-1`}
                                >
                                    Older
                                    <ChevronRight className="size-4" />
                                </Link>
                            )}
                        </span>
                    </nav>
                )}
            </OwnerPage>
        </>
    );
}
