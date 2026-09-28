import { Link, usePage } from '@inertiajs/react';
import {
    ArrowLeft,
    KeyRound,
    LogOut,
    Palette,
    Smartphone,
    UserRound,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';
import type { PropsWithChildren } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { PersonAvatar } from '@/components/person-avatar';
import { openPwaAppDialog, usePwaUi } from '@/hooks/use-pwa';
import { useCurrentUrl } from '@/hooks/use-current-url';
import { logout, workspace } from '@/routes';
import { edit as editAppearance } from '@/routes/appearance';
import { edit as editProfile } from '@/routes/profile';
import { edit as editSecurity } from '@/routes/security';
import type { Auth } from '@/types';

const SECTIONS: { title: string; href: ReturnType<typeof editProfile>; icon: LucideIcon }[] = [
    { title: 'Profile', href: editProfile(), icon: UserRound },
    { title: 'Security', href: editSecurity(), icon: KeyRound },
    { title: 'Appearance', href: editAppearance(), icon: Palette },
];

const item =
    'flex min-h-11 w-full items-center gap-2.5 rounded-xl px-3 text-[13px] font-semibold whitespace-nowrap transition-colors focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none';

/**
 * PONGSKILOG Account & preferences (Phase 20), the same for every role: Profile, Security, Appearance, the device's
 * App & notifications panel and Sign out, with a way back to the workspace. Only personal preferences live here;
 * organization identity and access stay in Staff administration.
 */
export default function AccountLayout({ children }: PropsWithChildren) {
    const { auth } = usePage<{ auth: Auth }>().props;
    const { isCurrentOrParentUrl } = useCurrentUrl();
    const pwa = usePwaUi();

    return (
        <div className="owner-surface min-h-dvh bg-neutral-100 pb-[env(safe-area-inset-bottom)] text-neutral-950">
            <header className="sticky top-0 z-20 border-b border-neutral-200 bg-white/95 backdrop-blur pt-[env(safe-area-inset-top)]">
                <div className="mx-auto flex h-16 max-w-5xl items-center gap-3 px-4">
                    <AppLogoIcon className="size-9 shrink-0" />
                    <div className="min-w-0 flex-1">
                        <p className="text-[10px] font-bold tracking-[0.16em] text-neutral-500 uppercase">
                            PONGSKILOG POS
                        </p>
                        <p className="truncate text-base font-bold">
                            Account &amp; preferences
                        </p>
                    </div>
                    <Link
                        href={workspace()}
                        className="flex min-h-11 shrink-0 items-center gap-2 rounded-xl border border-neutral-200 bg-white px-3 text-[13px] font-semibold hover:bg-neutral-50 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                    >
                        <ArrowLeft className="size-4" aria-hidden="true" />
                        <span className="hidden sm:inline">
                            Back to workspace
                        </span>
                        <span className="sm:hidden">Back</span>
                    </Link>
                </div>
            </header>

            <div className="mx-auto grid max-w-5xl gap-4 px-4 py-5 md:grid-cols-[240px_minmax(0,1fr)] md:gap-6 md:py-8">
                <aside className="flex flex-col gap-3">
                    <div className="flex items-center gap-3 rounded-2xl border border-neutral-200 bg-white p-3.5">
                        <PersonAvatar
                            name={auth.user?.displayName}
                            avatarUrl={auth.user?.avatarUrl}
                            className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-neutral-950 text-[15px] font-bold text-white"
                        />
                        <div className="min-w-0">
                            <p className="truncate text-sm font-bold">
                                {auth.user?.displayName}
                            </p>
                            <p className="truncate text-[11px] text-neutral-500">
                                {auth.roleLabel ?? 'Staff'}
                            </p>
                        </div>
                    </div>
                    <nav
                        aria-label="Account"
                        className="owner-hide-scrollbar flex gap-1 overflow-x-auto rounded-2xl border border-neutral-200 bg-white p-1.5 md:flex-col md:overflow-visible"
                    >
                        {SECTIONS.map(({ title, href, icon: Icon }) => {
                            const active = isCurrentOrParentUrl(href);

                            return (
                                <Link
                                    key={title}
                                    href={href}
                                    aria-current={active ? 'page' : undefined}
                                    className={`${item} ${active ? 'bg-neutral-950 text-white' : 'text-neutral-700 hover:bg-neutral-100'}`}
                                >
                                    <Icon className="size-4 shrink-0" aria-hidden="true" />
                                    {title}
                                </Link>
                            );
                        })}
                        {pwa.active && (
                            <button
                                type="button"
                                onClick={() => openPwaAppDialog()}
                                className={`${item} text-neutral-700 hover:bg-neutral-100`}
                            >
                                <Smartphone className="size-4 shrink-0" aria-hidden="true" />
                                App &amp; notifications
                            </button>
                        )}
                        <Link
                            href={logout()}
                            as="button"
                            className={`${item} text-red-700 hover:bg-red-50`}
                        >
                            <LogOut className="size-4 shrink-0" aria-hidden="true" />
                            Sign out
                        </Link>
                    </nav>
                </aside>

                <main className="flex min-w-0 flex-col gap-4">{children}</main>
            </div>
        </div>
    );
}
