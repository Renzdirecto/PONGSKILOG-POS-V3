import { Link, router, useHttp, usePage } from '@inertiajs/react';
import {
    BarChart3,
    ChefHat,
    LayoutDashboard,
    LogOut,
    MonitorUp,
    LayoutGrid,
    QrCode,
    ReceiptText,
    ShieldCheck,
    UtensilsCrossed,
} from 'lucide-react';
import { CustomerScreenControl } from '@/components/customer-screen-control';
import { PosProfileControls } from '@/components/pos-profile-controls';
import { PosReadyNotifications } from '@/components/pos-ready-notifications';
import { PwaStatus } from '@/components/pwa-status';
import AppLogoIcon from '@/components/app-logo-icon';
import { BranchSwitcher } from '@/components/branch-switcher';
import {
    managementDestinationHref,
    OwnerWorkspaceShell,
} from '@/components/owner-workspace-shell';
import { SuperAdminShell } from '@/components/super-admin-shell';
import { StoreStatusControl } from '@/components/store-status-control';
import { usePosQrRealtime } from '@/hooks/use-pos-qr-realtime';
import { useStoreClosedRealtime } from '@/hooks/use-store-closed-realtime';
import {
    handleRevalidationException,
    UserContextRealtime,
} from '@/hooks/use-user-context-realtime';
import { StoreSessionDetailsContext } from '@/hooks/use-store-session-details';
import {
    cashier,
    cashierDashboard,
    customerDisplay,
    kitchen,
    reports,
    superAdmin,
    transactionHistory,
} from '@/routes/workspaces';
import { logout } from '@/routes';
import { current as currentStoreSession } from '@/routes/store-sessions';
import { canOpenCustomerDisplay } from '@/lib/kitchen';
import {
    hasManagementPages,
    managementLandingDestination,
} from '@/lib/management-navigation';
import {
    discardsStoreSession,
    openStoreSessionDialogState,
    storeSessionLoadFailure,
    type StoreSessionLoadState,
} from '@/lib/store-session';
import type {
    Auth,
    BranchContext,
    CurrentStoreSession,
    PosReadyOrder,
    StoreClosedRealtimeEvent,
    StoreContext,
} from '@/types';
import { lazy, Suspense, useCallback, useRef, useState } from 'react';
import { toast } from 'sonner';

/**
 * Loaded on first open: the Store Session surface (expenses, stock corrections, giveaways, Close Store) is heavy and most
 * page visits never open it, so it stays out of the main bundle.
 */
const StoreSessionDetailsDialog = lazy(() =>
    import('@/components/store-session-details-dialog').then((module) => ({
        default: module.StoreSessionDetailsDialog,
    })),
);

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
    storeContext: StoreContext;
    workspace?: string;
    readyOrders?: PosReadyOrder[];
    qrWaitingCount?: number | null;
    surface?: string;
};

function StoreClosedListener({
    branchId,
    channel,
    onClosed,
    onOpened,
}: {
    branchId: string;
    channel: 'pos' | 'kitchen';
    onClosed: (event: StoreClosedRealtimeEvent) => void;
    onOpened: () => void;
}) {
    useStoreClosedRealtime(branchId, channel, onClosed, onOpened);

    return null;
}

const QR_WAITING_COUNT_PROPS = ['qrWaitingCount'];

/** Keeps the shared QR Orders badge current on every Store Operations page (POS, Dashboard, Kitchen, History). */
function QrWaitingCountListener({ branchId }: { branchId: string }) {
    usePosQrRealtime(branchId, QR_WAITING_COUNT_PROPS);

    return null;
}

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
    const [storeSessionLoadState, setStoreSessionLoadState] =
        useState<StoreSessionLoadState>('idle');
    /** The closing cashier keeps their success summary; other clients leave the stale session surface. */
    const ownClosedSessionId = useRef<string | null>(null);
    const isSuperAdmin = auth.roles.includes('super_admin');
    /** A Branch-scoped account with management pages (Products, Inventory, Operations, Staff, Settings) uses the management shell. */
    const branchManager =
        !branchContext.businessWide && hasManagementPages(auth.permissions);
    /**
     * A Custom Role running Branch operations can return to its management pages: business-wide ones, and Branch ones
     * holding management pages. The link opens the first management page directly (the workspace would land on POS).
     */
    const managementLanding =
        isSuperAdmin || (!branchContext.businessWide && !branchManager)
            ? null
            : managementLandingDestination(auth.permissions, {
                  businessWide: branchContext.businessWide,
              });
    const userContextRealtime = auth.user ? (
        <UserContextRealtime userId={auth.user.id} />
    ) : null;
    /** The server renders the POS only for accounts it authorized (Cashier roles, Branch custom roles, Super Admin). */
    const isPos =
        page.component === 'workspaces/order-summary' ||
        (page.component === 'workspaces/show' &&
            page.props.workspace === 'Cashier / POS' &&
            auth.permissions.includes('pos.access'));
    const isQr =
        isPos &&
        new URL(page.url, 'http://localhost').searchParams.get('view') === 'qr';
    const isKitchen = page.component === 'workspaces/kitchen';
    /** Owner and Super Admin read the same Transaction History page inside their management shell. */
    const isBusinessHistory =
        page.component === 'workspaces/transaction-history' &&
        page.props.surface === 'business';
    const isHistory =
        page.component === 'workspaces/transaction-history' &&
        !isBusinessHistory;
    const isDashboard = page.component === 'workspaces/cashier-dashboard';
    /**
     * Reports keeps the Store Operations navigation when it was opened from it (`?shell=pos`, like `?view=qr`), and
     * always for Branch staff whose only Reports access is this shell. An account with a management shell that opens
     * Reports from there has no marker, so it still reads the report inside that shell.
     */
    const isBranchReports =
        page.component === 'workspaces/reports' &&
        (new URL(page.url, 'http://localhost').searchParams.get('shell') ===
            'pos' ||
            (!branchContext.businessWide && !branchManager));
    const isOperational =
        isPos || isKitchen || isHistory || isDashboard || isBranchReports;
    const isOwnerManagement =
        page.component.startsWith('catalog/') ||
        page.component.startsWith('inventory/') ||
        page.component.startsWith('operations/') ||
        page.component.startsWith('super-admin/') ||
        page.component === 'branches/index' ||
        (page.component === 'workspaces/reports' && !isBranchReports) ||
        page.component === 'workspaces/owner-dashboard' ||
        isBusinessHistory;

    const refreshStoreSession = useCallback(async () => {
        setStoreSessionLoadState('loading');

        try {
            const detail = await storeSessionRequest.get(
                currentStoreSession.url(),
                { headers: { Accept: 'application/json' } },
            );
            setStoreSession(detail);
            setStoreSessionLoadState('loaded');
        } catch (reason) {
            const failure = storeSessionLoadFailure(reason);
            if (discardsStoreSession(failure)) {
                setStoreSession(null);
            }
            setStoreSessionLoadState(failure);
        }
    }, [storeSessionRequest]);

    if (isOperational) {
        /** The current-session details (and their expense / correction / giveaway actions) need both, server-side too. */
        const canViewStoreSession =
            auth.permissions.includes('pos.access') &&
            auth.permissions.includes('store_expenses.manage');
        const storeClosedChannel = auth.permissions.includes('pos.access')
            ? 'pos'
            : auth.permissions.includes('kitchen.access')
              ? 'kitchen'
              : null;
        const handleStoreClosed = (event: StoreClosedRealtimeEvent) => {
            setStoreSession(null);
            if (event.store_session_id !== ownClosedSessionId.current) {
                setStoreSessionDialogOpen(false);
                toast.info(
                    'The Store was closed. Operational actions are now disabled.',
                );
            }
            router.reload({
                onHttpException: handleRevalidationException,
                onNetworkError: () => false,
            });
        };
        /** Another device opened the Store: reload this page into its open state (the opener is already there). */
        const handleStoreOpened = () => {
            if (!page.props.storeContext?.isOpen) {
                router.reload({
                    onHttpException: handleRevalidationException,
                    onNetworkError: () => false,
                });
            }
        };
        const openStoreSessionDetails = async () => {
            const openingState = openStoreSessionDialogState(
                storeSession?.branch.id === branchContext.current?.id
                    ? storeSession
                    : null,
            );
            setStoreSessionDialogOpen(openingState.open);
            setStoreSession(openingState.session);
            setStoreSessionLoadState(openingState.loadState);

            await refreshStoreSession();
        };

        /** Only pages the account can open are listed; the server still authorizes each one. */
        const navigation = [
            {
                label: 'Dashboard',
                short: 'Home',
                icon: LayoutDashboard,
                available: auth.permissions.includes('pos.access'),
                href: cashierDashboard(),
                active: isDashboard,
            },
            {
                label: 'POS',
                short: 'POS',
                icon: UtensilsCrossed,
                available: auth.permissions.includes('pos.access'),
                href: cashier(),
                active: isPos && !isQr,
            },
            {
                label: 'QR Orders',
                short: 'QR',
                icon: QrCode,
                available: auth.permissions.includes('pos.access'),
                href: cashier({ query: { view: 'qr' } }),
                active: isQr,
            },
            {
                label: 'Kitchen',
                short: 'Kitchen',
                icon: ChefHat,
                available: auth.permissions.includes('kitchen.access'),
                href: kitchen(),
                active: isKitchen,
            },
            {
                label: 'History',
                short: 'History',
                icon: ReceiptText,
                available: auth.permissions.includes('transactions.view'),
                href: transactionHistory(),
                active: isHistory,
            },
            {
                label: 'Display',
                short: 'Display',
                icon: MonitorUp,
                available: canOpenCustomerDisplay(auth.permissions),
                href: customerDisplay(),
                active: false,
            },
            /**
             * Reports is the last operational destination for every account holding reports.view, Super Admin and
             * other business-wide viewers included; the server still decides which shell the report itself renders in.
             */
            {
                label: 'Reports',
                short: 'Reports',
                icon: BarChart3,
                available: auth.permissions.includes('reports.view'),
                href: reports({ query: { shell: 'pos' } }),
                active: isBranchReports,
            },
        ].filter((item) => item.available);
        return (
            <div className="pos-surface flex h-dvh overflow-hidden bg-[#111111] pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] text-[#111111]">
                {userContextRealtime}
                <aside className="theme-static hidden w-[94px] shrink-0 flex-col md:flex">
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
                            ({ label, icon: Icon, href, active }) => (
                                <Link
                                    key={label}
                                    href={href}
                                    preserveState
                                    preserveScroll
                                    aria-current={active ? 'page' : undefined}
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
                            ),
                        )}
                    </nav>
                    <span className="border-t border-white/10 p-3 text-center text-[9px] text-white/50">
                        {branchContext.current?.code}
                    </span>
                </aside>
                <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-[#fafafa]">
                    <header className="flex h-[60px] shrink-0 items-center gap-2 border-b border-neutral-200 bg-white px-3 min-[1180px]:h-[66px] min-[1180px]:px-4">
                        <img
                            src="/images/branding/logo.png"
                            alt="PONGSKILOG"
                            className="theme-static size-9 shrink-0 rounded-full bg-[#111] object-contain p-1 md:hidden"
                        />
                        <div className="min-w-0 flex-1">
                            <h1 className="truncate text-[15px] font-bold">
                                {isDashboard
                                    ? 'Dashboard'
                                    : isBranchReports
                                      ? 'Reports'
                                      : isKitchen
                                        ? 'Kitchen display'
                                        : isHistory
                                          ? 'Transaction history'
                                          : isQr
                                            ? 'QR Orders'
                                            : 'POS / Order'}
                            </h1>
                            <p className="truncate text-[11px] text-neutral-500">
                                {branchContext.current?.name}
                            </p>
                        </div>
                        <PwaStatus />
                        <StoreStatusControl
                            storeContext={page.props.storeContext}
                            branchName={branchContext.current?.name ?? null}
                            canViewSession={canViewStoreSession}
                            onViewSession={openStoreSessionDetails}
                        />
                        {branchContext.selectableBranches.length > 1 && (
                            <BranchSwitcher
                                branchContext={branchContext}
                                compact
                            />
                        )}
                        {/* This POS station's customer screen: the same control on every Store Operations page. */}
                        {auth.permissions.includes('pos.access') &&
                            branchContext.current && (
                                <CustomerScreenControl
                                    branchId={branchContext.current.id}
                                />
                            )}
                        {isPos && branchContext.current && (
                            <PosReadyNotifications
                                branchId={branchContext.current.id}
                                orders={page.props.readyOrders ?? []}
                                aboveCart={!isQr}
                            />
                        )}
                        {isSuperAdmin && (
                            <Link
                                href={superAdmin()}
                                aria-label="Back to Super Admin Control Center"
                                title="Super Admin Control Center"
                                className="inline-flex size-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-neutral-200 bg-white text-[12px] font-semibold text-neutral-700 hover:border-neutral-400 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none min-[1180px]:w-auto min-[1180px]:px-3"
                            >
                                <ShieldCheck className="size-4" />
                                <span className="hidden min-[1180px]:inline">
                                    Control Center
                                </span>
                            </Link>
                        )}
                        {managementLanding && (
                            <Link
                                href={managementDestinationHref(
                                    managementLanding.id,
                                    {
                                        hasBranch:
                                            branchContext.current !== null,
                                    },
                                )}
                                aria-label="Back to management"
                                title="Management"
                                className="inline-flex size-11 shrink-0 items-center justify-center gap-1.5 rounded-xl border border-neutral-200 bg-white text-[12px] font-semibold text-neutral-700 hover:border-neutral-400 focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none min-[1180px]:w-auto min-[1180px]:px-3"
                            >
                                <LayoutGrid className="size-4" />
                                <span className="hidden min-[1180px]:inline">
                                    Management
                                </span>
                            </Link>
                        )}
                        <PosProfileControls auth={auth} />
                    </header>
                    {storeSessionDialogOpen && branchContext.current && (
                        <Suspense fallback={null}>
                            <StoreSessionDetailsDialog
                                open
                                onOpenChange={setStoreSessionDialogOpen}
                                branchId={branchContext.current.id}
                                session={storeSession}
                                loadState={storeSessionLoadState}
                                refreshSession={refreshStoreSession}
                                canCloseStore={auth.permissions.includes(
                                    'store.open_close',
                                )}
                                canOpenKitchen={auth.permissions.includes(
                                    'kitchen.access',
                                )}
                                canOpenHistory={auth.permissions.includes(
                                    'transactions.view',
                                )}
                                onStoreClosing={(id) => {
                                    ownClosedSessionId.current = id;
                                }}
                                onStoreClosed={(result) => {
                                    ownClosedSessionId.current =
                                        result.store_session.id;
                                    setStoreSession(null);
                                }}
                            />
                        </Suspense>
                    )}
                    {branchContext.current &&
                        auth.permissions.includes('pos.access') && (
                            <QrWaitingCountListener
                                branchId={branchContext.current.id}
                            />
                        )}
                    {branchContext.current && storeClosedChannel && (
                        <StoreClosedListener
                            branchId={branchContext.current.id}
                            channel={storeClosedChannel}
                            onClosed={handleStoreClosed}
                            onOpened={handleStoreOpened}
                        />
                    )}
                    <main className="flex min-h-0 min-w-0 flex-1 flex-col overflow-auto pb-[calc(max(12px,env(safe-area-inset-bottom))+64px)] md:pb-0">
                        <StoreSessionDetailsContext.Provider
                            value={
                                page.props.storeContext?.isOpen &&
                                canViewStoreSession
                                    ? openStoreSessionDetails
                                    : null
                            }
                        >
                            {children}
                        </StoreSessionDetailsContext.Provider>
                    </main>
                    <nav
                        aria-label="Mobile operational navigation"
                        style={{
                            gridTemplateColumns: `repeat(${navigation.length}, minmax(0, 1fr))`,
                        }}
                        className="theme-static fixed right-[max(12px,env(safe-area-inset-right))] bottom-[max(12px,env(safe-area-inset-bottom))] left-[max(12px,env(safe-area-inset-left))] z-30 mx-auto grid h-16 max-w-[620px] gap-1 rounded-[20px] bg-[#111111] p-1.5 shadow-xl md:hidden"
                    >
                        {navigation.map(
                            ({ label, short, icon: Icon, href, active }) => (
                                <Link
                                    key={label}
                                    href={href}
                                    preserveState
                                    preserveScroll
                                    aria-label={label}
                                    aria-current={active ? 'page' : undefined}
                                    className={`relative flex h-full min-w-0 flex-col items-center justify-center gap-0.5 rounded-xl px-0.5 text-center text-[10px] font-semibold ${active ? 'bg-white text-neutral-950' : 'text-white/65'}`}
                                >
                                    <Icon className="size-5 shrink-0" />
                                    <span className="max-w-full truncate">
                                        {short}
                                    </span>
                                    {label === 'QR Orders' &&
                                        (page.props.qrWaitingCount ?? 0) >
                                            0 && (
                                            <span className="absolute top-1 right-1 min-w-4 rounded-full bg-red-700 px-1 text-center text-[9px] leading-4 text-white">
                                                {page.props.qrWaitingCount}
                                            </span>
                                        )}
                                </Link>
                            ),
                        )}
                    </nav>
                </div>
            </div>
        );
    }

    if (isOwnerManagement) {
        return (
            <>
                {userContextRealtime}
                {isSuperAdmin ? (
                    <SuperAdminShell>{children}</SuperAdminShell>
                ) : (
                    <OwnerWorkspaceShell>{children}</OwnerWorkspaceShell>
                )}
            </>
        );
    }

    return (
        <div className="min-h-svh bg-[#f4f4f3] text-neutral-950">
            {userContextRealtime}
            <header className="border-b border-neutral-200 bg-white">
                <div className="mx-auto flex min-h-18 max-w-[96rem] flex-wrap items-center gap-3 px-4 py-3 sm:px-6 lg:px-8">
                    <div className="flex min-w-0 items-center gap-3 sm:mr-auto">
                        <AppLogoIcon alt="" className="size-11 shrink-0" />
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

                    <PwaStatus />
                    {auth.user && (
                        <div className="ml-auto flex items-center gap-2 sm:ml-0">
                            <div className="hidden text-right md:block">
                                <p className="text-sm font-semibold">
                                    {auth.user.displayName}
                                </p>
                                <p className="text-xs text-neutral-500">
                                    {auth.roleLabel ?? roleLabel(auth.roles[0])}
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
