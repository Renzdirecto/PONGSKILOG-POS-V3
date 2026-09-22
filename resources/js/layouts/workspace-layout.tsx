import { Link, useHttp, usePage } from '@inertiajs/react';
import {
    ChefHat,
    LayoutDashboard,
    LogOut,
    MonitorUp,
    QrCode,
    UtensilsCrossed,
} from 'lucide-react';
import { PosProfileControls } from '@/components/pos-profile-controls';
import { PosReadyNotifications } from '@/components/pos-ready-notifications';
import { BranchSwitcher } from '@/components/branch-switcher';
import { OwnerWorkspaceShell } from '@/components/owner-workspace-shell';
import { StoreSessionDetailsDialog } from '@/components/store-session-details-dialog';
import { cashier, customerDisplay, kitchen } from '@/routes/workspaces';
import { logout } from '@/routes';
import { current as currentStoreSession } from '@/routes/store-sessions';
import { canOpenCustomerDisplay } from '@/lib/kitchen';
import { openStoreSessionDialogState } from '@/lib/store-session';
import type {
    Auth,
    BranchContext,
    CurrentStoreSession,
    PosReadyOrder,
    StoreContext,
} from '@/types';
import { useState } from 'react';

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
    storeContext: StoreContext;
    workspace?: string;
    readyOrders?: PosReadyOrder[];
    qrWaitingCount?: number;
};

function roleLabel(role?: string): string {
    if (!role) {
        return 'Staff';
    }

    return role
        .split('_')
        .map((part) => part.charAt(0).toUpperCase() + part.slice(1))
        .join(' ');
}

export default function WorkspaceLayout({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage<SharedProps>();
    const { auth, branchContext } = page.props;
    const storeSessionRequest = useHttp<
        Record<string, never>,
        CurrentStoreSession
    >({});
    const [storeSessionDialogOpen, setStoreSessionDialogOpen] = useState(false);
    const [storeSession, setStoreSession] =
        useState<CurrentStoreSession | null>(null);
    const [storeSessionUnavailable, setStoreSessionUnavailable] =
        useState(false);
    const isPos =
        page.component === 'workspaces/order-summary' ||
        (page.component === 'workspaces/show' &&
            page.props.workspace === 'Cashier / POS' &&
            auth.roles.some(
                (role) => role === 'cashier' || role === 'cashier_kitchen',
            ));
    const isQr =
        isPos &&
        new URL(page.url, 'http://localhost').searchParams.get('view') === 'qr';
    const isKitchen = page.component === 'workspaces/kitchen';
    const isOperational = isPos || isKitchen;
    const isOwnerManagement =
        page.component.startsWith('catalog/') ||
        page.component.startsWith('inventory/') ||
        page.component === 'branches/index' ||
        (page.component === 'workspaces/show' &&
            (page.props.workspace === 'Owner' ||
                page.props.workspace === 'Super Admin'));

    if (isOperational) {
        const openStoreSessionDetails = async () => {
            const openingState = openStoreSessionDialogState();
            setStoreSessionDialogOpen(openingState.open);
            setStoreSession(openingState.session);
            setStoreSessionUnavailable(openingState.unavailable);

            try {
                const detail = await storeSessionRequest.submit(
                    currentStoreSession(),
                    {
                        onHttpException: () => true,
                        onNetworkError: () => true,
                    },
                );
                setStoreSession(detail);
            } catch {
                setStoreSessionUnavailable(true);
            }
        };

        const navigation = [
            {
                label: 'Dashboard',
                icon: LayoutDashboard,
                available: false,
                href: null,
                active: false,
            },
            {
                label: 'POS / Order',
                icon: UtensilsCrossed,
                available: auth.permissions.includes('pos.access'),
                href: cashier(),
                active: isPos && !isQr,
            },
            {
                label: 'Kitchen',
                icon: ChefHat,
                available: auth.permissions.includes('kitchen.access'),
                href: kitchen(),
                active: isKitchen,
            },
            {
                label: 'Display',
                icon: MonitorUp,
                available: canOpenCustomerDisplay(auth.permissions),
                href: customerDisplay(),
                active: false,
            },
            {
                label: 'QR Orders',
                icon: QrCode,
                available: auth.permissions.includes('pos.access'),
                href: cashier({ query: { view: 'qr' } }),
                active: isQr,
            },
        ];
        return (
            <div className="pos-surface flex h-dvh overflow-hidden bg-[#111111] text-[#111111]">
                <aside className="hidden w-[94px] shrink-0 flex-col md:flex">
                    <div className="flex h-[72px] shrink-0 items-center justify-center border-b border-white/10 px-3">
                        <img
                            src="/images/branding/logo.png"
                            alt="PONGSKILOG"
                            className="w-[66px]"
                        />
                    </div>
                    <nav
                        aria-label="Operational navigation"
                        className="flex flex-1 flex-col gap-1.5 px-2 py-2.5"
                    >
                        {navigation.map(
                            ({ label, icon: Icon, available, href, active }) =>
                                available && href ? (
                                    <Link
                                        key={label}
                                        href={href}
                                        preserveState
                                        preserveScroll
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={`flex h-16 flex-col items-center justify-center gap-1 rounded-[14px] px-1 text-center text-[10px] font-semibold ${active ? 'bg-white text-neutral-950' : 'text-white/65 hover:bg-white/10 hover:text-white'}`}
                                    >
                                        <Icon className="size-5" />
                                        {label}
                                        {label === 'QR Orders' &&
                                            (page.props.qrWaitingCount ?? 0) >
                                                0 && (
                                                <span className="rounded-full bg-red-700 px-1.5 text-[9px] leading-4 text-white">
                                                    {page.props.qrWaitingCount}
                                                </span>
                                            )}
                                    </Link>
                                ) : (
                                    <button
                                        key={label}
                                        disabled
                                        title={`${label} is not available yet`}
                                        className="flex min-h-16 flex-col items-center justify-center gap-1 rounded-[14px] px-1 text-center text-[10px] leading-tight font-semibold text-white/45"
                                    >
                                        <Icon className="size-5" />
                                        {label}
                                        <span className="text-[8px] font-normal">
                                            Coming later
                                        </span>
                                    </button>
                                ),
                        )}
                    </nav>
                    <span className="border-t border-white/10 p-3 text-center text-[9px] text-white/50">
                        {branchContext.current?.code}
                    </span>
                </aside>
                <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-[#fafafa]">
                    <header className="flex h-[60px] shrink-0 items-center gap-2 border-b border-neutral-200 bg-white px-3 min-[1180px]:h-[66px] min-[1180px]:px-4">
                        <div className="min-w-0 flex-1">
                            <h1 className="truncate text-[15px] font-bold">
                                {isKitchen
                                    ? 'Kitchen display'
                                    : isQr
                                      ? 'QR Orders'
                                      : 'POS / Order'}
                            </h1>
                            <p className="truncate text-[11px] text-neutral-500">
                                {branchContext.current?.name}
                            </p>
                        </div>
                        {page.props.storeContext?.isOpen && isPos ? (
                            <button
                                type="button"
                                aria-label="View current Store Session details"
                                title="View current Store Session details"
                                onClick={openStoreSessionDetails}
                                className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border border-green-200 bg-green-50 px-2 text-[9px] font-semibold text-green-700 transition hover:border-green-300 hover:bg-green-100 focus-visible:ring-2 focus-visible:ring-green-700 focus-visible:outline-none md:px-[11px] md:text-[11.5px]"
                            >
                                <span className="size-[7px] rounded-full bg-green-700" />
                                <span className="hidden sm:inline">STORE</span>{' '}
                                OPEN
                            </button>
                        ) : page.props.storeContext?.isOpen ? (
                            <span
                                aria-label="Store open"
                                className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border border-green-200 bg-green-50 px-2 text-[9px] font-semibold text-green-700 md:px-[11px] md:text-[11.5px]"
                            >
                                <span className="size-[7px] rounded-full bg-green-700" />
                                <span className="hidden sm:inline">STORE</span>{' '}
                                OPEN
                            </span>
                        ) : (
                            <span
                                aria-label="Store closed"
                                className="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-full border border-neutral-200 bg-neutral-50 px-2 text-[9px] font-semibold text-neutral-500 md:px-[11px] md:text-[11.5px]"
                            >
                                <span className="size-[7px] rounded-full bg-neutral-400" />
                                <span className="hidden sm:inline">STORE</span>{' '}
                                CLOSED
                            </span>
                        )}
                        {branchContext.selectableBranches.length > 1 && (
                            <BranchSwitcher
                                branchContext={branchContext}
                                compact
                            />
                        )}
                        {isPos && branchContext.current && (
                            <PosReadyNotifications
                                branchId={branchContext.current.id}
                                orders={page.props.readyOrders ?? []}
                            />
                        )}
                        <PosProfileControls auth={auth} />
                    </header>
                    <StoreSessionDetailsDialog
                        open={storeSessionDialogOpen}
                        onOpenChange={setStoreSessionDialogOpen}
                        session={storeSession}
                        loading={storeSessionRequest.processing}
                        unavailable={storeSessionUnavailable}
                    />
                    <main className="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto pb-[76px] md:pb-0">
                        {children}
                    </main>
                    <nav
                        aria-label="Mobile operational navigation"
                        className="fixed right-3 bottom-[max(12px,env(safe-area-inset-bottom))] left-3 z-30 mx-auto grid h-16 max-w-[520px] grid-cols-5 gap-1 rounded-[20px] bg-[#111111] p-1.5 shadow-xl md:hidden"
                    >
                        {navigation.map(
                            ({ label, icon: Icon, available, href, active }) =>
                                available && href ? (
                                    <Link
                                        key={label}
                                        href={href}
                                        preserveState
                                        preserveScroll
                                        aria-current={
                                            active ? 'page' : undefined
                                        }
                                        className={`flex flex-col items-center justify-center gap-1 rounded-xl text-center text-[10px] font-semibold ${active ? 'bg-white text-neutral-950' : 'text-white/65'}`}
                                    >
                                        <Icon className="size-5" />
                                        {label}
                                        {label === 'QR Orders' &&
                                            (page.props.qrWaitingCount ?? 0) >
                                                0 && (
                                                <span className="rounded-full bg-red-700 px-1.5 text-[9px] leading-4 text-white">
                                                    {page.props.qrWaitingCount}
                                                </span>
                                            )}
                                    </Link>
                                ) : (
                                    <button
                                        key={label}
                                        disabled
                                        className="flex flex-col items-center justify-center gap-1 text-center text-[9px] leading-tight text-white/45"
                                    >
                                        <Icon className="size-5" />
                                        {label}
                                        <span className="text-[8px]">
                                            Coming later
                                        </span>
                                    </button>
                                ),
                        )}
                    </nav>
                </div>
            </div>
        );
    }

    if (isOwnerManagement) {
        return <OwnerWorkspaceShell>{children}</OwnerWorkspaceShell>;
    }

    return (
        <div className="min-h-svh bg-[#f4f4f3] text-neutral-950">
            <header className="border-b border-neutral-200 bg-white">
                <div className="mx-auto flex min-h-18 max-w-[96rem] flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                    <div className="flex min-w-0 items-center gap-3 sm:mr-auto">
                        <div className="flex h-11 w-20 shrink-0 items-center justify-center overflow-hidden rounded-lg bg-neutral-950 px-2">
                            <img
                                src="/images/branding/logo.png"
                                alt="PONGSKILOG"
                                className="h-auto w-full"
                            />
                        </div>
                        <div className="hidden sm:block">
                            <p className="text-sm font-bold tracking-[0.12em] uppercase">
                                PONGSKILOG
                            </p>
                            <p className="text-[0.62rem] font-semibold tracking-[0.17em] text-neutral-400 uppercase">
                                Operations
                            </p>
                        </div>
                    </div>

                    <div className="order-3 w-full sm:order-none sm:w-auto">
                        <BranchSwitcher branchContext={branchContext} />
                    </div>

                    {auth.user && (
                        <div className="ml-auto flex items-center gap-2 sm:ml-0">
                            <div className="hidden text-right md:block">
                                <p className="text-sm font-semibold">
                                    {auth.user.name}
                                </p>
                                <p className="text-xs text-neutral-500">
                                    {roleLabel(auth.roles[0])}
                                </p>
                            </div>
                            <Link
                                href={logout()}
                                as="button"
                                className="inline-flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-neutral-600 transition hover:bg-neutral-100 hover:text-neutral-950 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                                aria-label="Log out"
                            >
                                <LogOut className="size-4" />
                            </Link>
                        </div>
                    )}
                </div>
            </header>

            <main
                className={`mx-auto w-full max-w-[96rem] ${isPos ? 'px-3 py-4 sm:px-4' : 'px-4 py-8 sm:px-6 lg:px-8 lg:py-12'}`}
            >
                {children}
            </main>
        </div>
    );
}
