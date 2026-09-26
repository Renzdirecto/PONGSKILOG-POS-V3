import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Boxes,
    ChefHat,
    Layers,
    LayoutDashboard,
    LayoutGrid,
    Leaf,
    ListChecks,
    LogOut,
    Menu,
    MonitorUp,
    PackageSearch,
    PanelLeftClose,
    PanelLeftOpen,
    QrCode,
    ReceiptText,
    Settings,
    ShoppingBasket,
    ShoppingCart,
    UserRound,
    Users,
    UtensilsCrossed,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useState } from 'react';
import AppLogoIcon from '@/components/app-logo-icon';
import { BranchSwitcher } from '@/components/branch-switcher';
import { PersonAvatar } from '@/components/person-avatar';
import { PwaAppMenuItem } from '@/components/pwa-app-dialog';
import { PwaStatus } from '@/components/pwa-status';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    activeManagementDestination,
    identitySubtitle,
    MANAGEMENT_SIDEBAR_STORAGE_KEY,
    managementDestinations,
    managementNavigation,
    pinnedManagementDestinations,
    restoredSidebarCollapsed,
    storedSidebarValue,
    type ManagementDestination,
    type ManagementDestinationId,
} from '@/lib/management-navigation';
import {
    index as branchesIndex,
    select as selectBranch,
} from '@/routes/branches';
import { index as inventoryIndex } from '@/routes/inventory';
import { logout, workspace } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { index as productsIndex } from '@/routes/products';
import { index as staffIndex } from '@/routes/staff';
import operationsRoutes from '@/routes/operations';
import {
    cashier,
    customerDisplay,
    kitchen,
    owner,
    reports,
    transactions,
} from '@/routes/workspaces';
import type { Auth, BranchContext } from '@/types';

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
    workspace?: string;
    surface?: string;
};

type OperationsPageProps = {
    operations?: { active_plan_id: string | null };
};

type RouteTarget = ReturnType<typeof owner>;

/** Every registry destination binds to an icon; hrefs are resolved per render (Branch and active Plan aware). */
const destinationIcons: Record<ManagementDestinationId, LucideIcon> = {
    dashboard: LayoutDashboard,
    pos: UtensilsCrossed,
    'qr-orders': QrCode,
    kitchen: ChefHat,
    'customer-display': MonitorUp,
    transactions: ReceiptText,
    reports: BarChart3,
    products: Boxes,
    inventory: PackageSearch,
    plans: ShoppingBasket,
    overview: LayoutGrid,
    ingredients: Leaf,
    recipes: ListChecks,
    stock: Layers,
    pamamalengke: ShoppingCart,
    purchases: ReceiptText,
    staff: Users,
    settings: Settings,
};

const focusRing =
    'focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none';

/**
 * The href of a management destination. Store Operations always run at one concrete Branch: with a Branch selected the
 * link opens it, otherwise the Branch picker comes first and then continues to the workspace. Operations links keep
 * the URL-addressable active Plan.
 */
export function managementDestinationHref(
    id: ManagementDestinationId,
    { hasBranch, planId }: { hasBranch: boolean; planId?: string | null },
): RouteTarget {
    const branchOperation = (route: RouteTarget) =>
        hasBranch ? route : selectBranch({ query: { redirect: route.url } });

    switch (id) {
        case 'dashboard':
            return owner();
        case 'pos':
            return branchOperation(cashier());
        case 'qr-orders':
            return branchOperation(cashier({ query: { view: 'qr' } }));
        case 'kitchen':
            return branchOperation(kitchen());
        case 'customer-display':
            return branchOperation(customerDisplay());
        case 'transactions':
            return transactions();
        case 'reports':
            return reports();
        case 'products':
            return productsIndex();
        case 'inventory':
            return inventoryIndex();
        case 'staff':
            return staffIndex();
        case 'settings':
            return branchesIndex();
        default:
            return operationsRoutes[id](
                planId ? { query: { plan: planId } } : undefined,
            );
    }
}

/**
 * One permitted destination. `full` is the expanded sidebar row, `rail` the collapsed desktop icon (named through
 * aria-label and a tooltip), `compact` the tablet rail and mobile dock tile.
 */
function NavigationControl({
    destination,
    href,
    active,
    variant,
    onNavigate,
}: {
    destination: ManagementDestination;
    href: RouteTarget;
    active: boolean;
    variant: 'full' | 'rail' | 'compact';
    onNavigate?: () => void;
}) {
    const Icon = destinationIcons[destination.id];
    const tone = active
        ? 'bg-white font-semibold text-[#111111]'
        : 'text-white/70 hover:bg-white/10 hover:text-white';
    const className = {
        full: `flex h-[46px] w-full items-center gap-3 rounded-[10px] px-3 text-left text-sm font-medium transition ${focusRing} ${tone}`,
        rail: `mx-auto flex size-11 items-center justify-center rounded-[10px] transition ${focusRing} ${tone}`,
        compact: `relative flex h-[70px] w-full flex-col items-center justify-center gap-1.5 rounded-xl px-1 text-center text-[10px] leading-tight font-semibold transition ${focusRing} ${tone}`,
    }[variant];

    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            aria-label={variant === 'rail' ? destination.label : undefined}
            title={variant === 'full' ? undefined : destination.label}
            className={className}
            onClick={onNavigate}
        >
            <Icon className="size-[18px] shrink-0" aria-hidden="true" />
            {variant === 'full' && (
                <span className="min-w-0 flex-1 truncate">
                    {destination.label}
                </span>
            )}
            {variant === 'compact' && <span>{destination.shortLabel}</span>}
        </Link>
    );
}

/** Section headings sit above their pages: brighter and bolder than before, still below the active page. */
function SectionHeading({ label, touch }: { label: string; touch?: boolean }) {
    return (
        <p
            className={`${touch ? 'px-3' : 'px-2.5'} pb-2 text-[10.5px] font-bold tracking-[0.12em] text-white/80 uppercase`}
        >
            {label}
        </p>
    );
}

export function OwnerWorkspaceShell({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage<SharedProps & OperationsPageProps>();
    const { auth, branchContext } = page.props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    const [collapsed, setCollapsed] = useState(false);
    /** Super Admin uses the dedicated collapsible SuperAdminShell; this shell serves the Owner and business-wide custom roles. */
    const workspaceLabel = auth.roleLabel ?? 'Owner';
    /** Position (business/job title) names the person; it never grants access. Falls back to the Role label. */
    const identityLabel = identitySubtitle(auth.user?.position, workspaceLabel);
    const canReports = auth.permissions.includes('reports.view');
    const groups = managementNavigation(auth.permissions, {
        businessWide: branchContext.businessWide,
    });
    const destinations = groups.flatMap((group) => group.destinations);
    const activeId = activeManagementDestination({
        component: page.component,
        surface: page.props.surface,
    });
    const pinned = pinnedManagementDestinations(destinations);
    const activeInMenu =
        activeId !== null &&
        !pinned.some((destination) => destination.id === activeId);
    /** Operations links keep the URL-addressable active Plan while moving between Operations pages. */
    const hrefFor = (id: ManagementDestinationId): RouteTarget =>
        managementDestinationHref(id, {
            hasBranch: branchContext.current !== null,
            planId: page.props.operations?.active_plan_id,
        });
    const avatarUrl = auth.user?.avatarUrl ?? null;
    const pageTitle =
        managementDestinations.find(
            (destination) => destination.id === activeId,
        )?.label ??
        (page.component.startsWith('operations/') ? 'Operations' : 'Dashboard');
    /** A Branch-scoped account never has an All Branches scope; its pages always run on a selected assigned Branch. */
    const currentScope = branchContext.current
        ? `${branchContext.current.name} · ${branchContext.current.code}`
        : branchContext.businessWide
          ? 'All Branches'
          : 'Choose a Branch';
    const canSettings = destinations.some(
        (destination) => destination.id === 'settings',
    );

    useEffect(() => {
        try {
            setCollapsed(
                restoredSidebarCollapsed(
                    window.localStorage.getItem(MANAGEMENT_SIDEBAR_STORAGE_KEY),
                ),
            );
        } catch {
            /** Storage can be unavailable (private mode); the sidebar simply starts expanded. */
        }
    }, []);

    function toggleCollapsed() {
        const next = !collapsed;
        setCollapsed(next);
        try {
            window.localStorage.setItem(
                MANAGEMENT_SIDEBAR_STORAGE_KEY,
                storedSidebarValue(next),
            );
        } catch {
            /** The preference is a convenience only. */
        }
    }

    return (
        <div className="owner-surface flex h-dvh overflow-hidden bg-[#111111] pr-[env(safe-area-inset-right)] pl-[env(safe-area-inset-left)] text-[#111111] print:block print:h-auto print:overflow-visible print:bg-white print:p-0">
            <aside
                data-collapsed={collapsed}
                className={`hidden shrink-0 flex-col overflow-hidden bg-[#111111] transition-[width] duration-200 ease-out motion-reduce:transition-none min-[1180px]:flex print:hidden! ${collapsed ? 'w-[76px]' : 'w-[248px]'}`}
            >
                <div
                    className={`flex h-[72px] shrink-0 items-center border-b border-white/10 ${collapsed ? 'justify-center px-2' : 'justify-between gap-2 pr-3 pl-4'}`}
                >
                    {!collapsed && (
                        <img
                            src="/images/branding/logo.png"
                            alt="PONGSKILOG"
                            className="w-[168px]"
                        />
                    )}
                    <button
                        type="button"
                        onClick={toggleCollapsed}
                        aria-expanded={!collapsed}
                        aria-controls="management-sidebar-navigation"
                        aria-label={
                            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                        }
                        title={
                            collapsed ? 'Expand sidebar' : 'Collapse sidebar'
                        }
                        className={`flex size-10 shrink-0 items-center justify-center rounded-lg text-white/70 transition hover:bg-white/10 hover:text-white ${focusRing}`}
                    >
                        {collapsed ? (
                            <PanelLeftOpen
                                className="size-[18px]"
                                aria-hidden="true"
                            />
                        ) : (
                            <PanelLeftClose
                                className="size-[18px]"
                                aria-hidden="true"
                            />
                        )}
                    </button>
                </div>
                <div
                    className={`shrink-0 border-b border-white/10 ${collapsed ? 'flex justify-center px-2 py-3' : 'p-3'}`}
                >
                    {collapsed ? (
                        <span
                            title={`${workspaceLabel} workspace`}
                            className="flex size-11 items-center justify-center rounded-xl border border-white/20 bg-white/10 text-white"
                        >
                            <LayoutDashboard
                                className="size-[18px]"
                                aria-hidden="true"
                            />
                            <span className="sr-only">
                                {workspaceLabel} workspace
                            </span>
                        </span>
                    ) : (
                        <div className="flex h-14 items-center gap-3 rounded-xl border border-white/20 bg-white/10 px-3">
                            <span className="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-white/10 text-white">
                                <LayoutDashboard
                                    className="size-[18px]"
                                    aria-hidden="true"
                                />
                            </span>
                            <span className="min-w-0">
                                <span className="block text-[10px] font-semibold tracking-[0.1em] text-white/50 uppercase">
                                    Workspace
                                </span>
                                <span className="block truncate text-sm font-semibold text-white">
                                    {workspaceLabel}
                                </span>
                            </span>
                        </div>
                    )}
                </div>
                <nav
                    id="management-sidebar-navigation"
                    aria-label={`${workspaceLabel} navigation`}
                    className={`owner-hide-scrollbar flex-1 overflow-x-hidden overflow-y-auto py-3.5 ${collapsed ? 'px-2' : 'px-2.5'}`}
                >
                    {groups.map(({ section, destinations: items }, index) => (
                        <div
                            key={section.id}
                            role="group"
                            aria-label={section.label}
                            className={collapsed ? 'mb-2' : 'mb-4'}
                        >
                            {collapsed ? (
                                index > 0 && (
                                    <hr
                                        aria-hidden="true"
                                        className="mx-auto mb-2 w-8 border-white/15"
                                    />
                                )
                            ) : (
                                <SectionHeading label={section.label} />
                            )}
                            <div
                                className={`flex flex-col ${collapsed ? 'gap-1' : 'gap-0.5'}`}
                            >
                                {items.map((destination) => (
                                    <NavigationControl
                                        key={destination.id}
                                        destination={destination}
                                        href={hrefFor(destination.id)}
                                        active={destination.id === activeId}
                                        variant={collapsed ? 'rail' : 'full'}
                                    />
                                ))}
                            </div>
                        </div>
                    ))}
                </nav>
                <div className="shrink-0 border-t border-white/10 p-3">
                    {collapsed ? (
                        <div className="flex flex-col items-center gap-2 text-white">
                            <span
                                title={`${auth.user?.name ?? ''} · ${identityLabel}`}
                                className="flex size-[34px] items-center justify-center overflow-hidden rounded-full bg-white/12 text-xs font-semibold"
                            >
                                <PersonAvatar
                                    name={auth.user?.name}
                                    avatarUrl={avatarUrl}
                                    className="flex size-full items-center justify-center"
                                />
                                <span className="sr-only">
                                    {auth.user?.name}, {identityLabel}
                                </span>
                            </span>
                            <Link
                                href={logout()}
                                method="post"
                                as="button"
                                aria-label="Log out"
                                title="Log out"
                                className={`flex size-10 items-center justify-center rounded-lg text-white/70 hover:bg-white/10 hover:text-white ${focusRing}`}
                            >
                                <LogOut className="size-4" aria-hidden="true" />
                            </Link>
                        </div>
                    ) : (
                        <div className="flex min-h-14 items-center gap-3 rounded-[10px] px-3 text-white">
                            <PersonAvatar
                                name={auth.user?.name}
                                avatarUrl={avatarUrl}
                                className="flex size-[34px] shrink-0 items-center justify-center overflow-hidden rounded-full bg-white/12 text-xs font-semibold"
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block truncate text-[13px] font-semibold">
                                    {auth.user?.name}
                                </span>
                                <span className="block truncate text-[11px] text-white/60">
                                    {identityLabel}
                                </span>
                            </span>
                            <Link
                                href={logout()}
                                method="post"
                                as="button"
                                className={`rounded-lg px-2 py-2 text-[11px] font-semibold text-white/70 hover:bg-white/10 hover:text-white ${focusRing}`}
                            >
                                Log out
                            </Link>
                        </div>
                    )}
                </div>
            </aside>

            <aside className="hidden w-24 shrink-0 flex-col bg-[#111111] min-[1180px]:hidden! md:flex print:hidden!">
                <Link
                    href={canReports ? owner() : workspace()}
                    className={`flex h-[82px] flex-col items-center justify-center gap-1 border-b border-white/10 px-2 focus-visible:ring-inset ${focusRing}`}
                >
                    <img
                        src="/images/branding/logo.png"
                        alt="PONGSKILOG"
                        className="max-w-[70px]"
                    />
                    <span className="max-w-full truncate text-[9px] font-bold tracking-[0.08em] text-white/60 uppercase">
                        {workspaceLabel}
                    </span>
                </Link>
                <p className="px-1.5 pt-2 text-center text-[9px] font-semibold tracking-[0.06em] text-white/40 uppercase">
                    {branchContext.current?.code ??
                        (branchContext.businessWide ? 'All branches' : '')}
                </p>
                <nav
                    aria-label={`${workspaceLabel} navigation`}
                    className="owner-hide-scrollbar flex flex-1 flex-col gap-1.5 overflow-y-auto px-2 py-2"
                >
                    {destinations.map((destination) => (
                        <NavigationControl
                            key={destination.id}
                            destination={destination}
                            href={hrefFor(destination.id)}
                            active={destination.id === activeId}
                            variant="compact"
                        />
                    ))}
                </nav>
                <div className="flex flex-col items-center border-t border-white/10 p-2.5">
                    <Link
                        href={logout()}
                        method="post"
                        as="button"
                        aria-label="Log out"
                        title="Log out"
                        className={`flex size-11 items-center justify-center overflow-hidden rounded-full bg-white/12 text-xs font-semibold text-white ${focusRing}`}
                    >
                        <PersonAvatar
                            name={auth.user?.name}
                            avatarUrl={avatarUrl}
                            className="flex size-full items-center justify-center"
                        />
                    </Link>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-white print:block print:overflow-visible">
                <header className="flex h-[60px] shrink-0 items-center gap-2.5 border-b border-[#e5e5e5] bg-white px-3 md:h-[72px] md:gap-3.5 md:px-5 print:hidden">
                    <AppLogoIcon className="size-9 shrink-0 md:hidden" />
                    <p className="min-w-0 flex-1 truncate text-base font-semibold tracking-[-0.01em] md:hidden">
                        {pageTitle}
                    </p>
                    <div className="hidden min-w-0 flex-1 md:block">
                        <BranchSwitcher
                            branchContext={branchContext}
                            redirectTo={page.url}
                        />
                    </div>
                    <div className="md:hidden">
                        <BranchSwitcher
                            branchContext={branchContext}
                            redirectTo={page.url}
                            compact
                        />
                    </div>
                    <div className="hidden min-w-0 flex-1 text-right md:block">
                        <p className="truncate text-[13px] font-semibold">
                            {auth.user?.name}
                        </p>
                        <p className="truncate text-[11px] text-neutral-500">
                            {workspaceLabel} · {currentScope}
                        </p>
                    </div>
                    <PwaStatus />
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Open account menu"
                                title="Account"
                                className="flex size-11 shrink-0 items-center justify-center overflow-hidden rounded-full bg-[#111] text-xs font-bold text-white focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                <PersonAvatar
                                    name={auth.user?.name}
                                    avatarUrl={avatarUrl}
                                    className="flex size-full items-center justify-center"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="owner-surface w-[min(300px,calc(100vw-24px))] rounded-xl p-2"
                        >
                            <DropdownMenuLabel className="space-y-0.5">
                                <span className="block truncate text-[13px] font-semibold">
                                    {auth.user?.name}
                                </span>
                                <span className="block text-[11px] font-normal text-[#666]">
                                    {identityLabel} · {currentScope}
                                </span>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <Link href={editProfile()} className="min-h-10">
                                    <UserRound className="size-4" /> Account
                                    profile
                                </Link>
                            </DropdownMenuItem>
                            {canSettings && (
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={branchesIndex()}
                                        className="min-h-10"
                                    >
                                        <Settings className="size-4" /> Settings
                                    </Link>
                                </DropdownMenuItem>
                            )}
                            <PwaAppMenuItem />
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild variant="destructive">
                                <Link
                                    href={logout()}
                                    method="post"
                                    as="button"
                                    className="min-h-10 w-full"
                                >
                                    Log out
                                </Link>
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </header>
                <main className="owner-scrollbar relative flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto bg-[#f7f7f7] pb-[calc(92px+env(safe-area-inset-bottom,0px))] md:pb-0 print:block print:overflow-visible print:bg-white print:pb-0">
                    {children}
                </main>
            </div>

            <nav
                aria-label={`Mobile ${workspaceLabel} navigation`}
                style={{
                    gridTemplateColumns: `repeat(${pinned.length + 1}, minmax(0, 1fr))`,
                }}
                className="fixed right-[max(12px,env(safe-area-inset-right))] bottom-[max(12px,env(safe-area-inset-bottom))] left-[max(12px,env(safe-area-inset-left))] z-40 mx-auto grid h-[68px] max-w-[430px] gap-1 rounded-[20px] bg-[#111111] p-1.5 shadow-2xl md:hidden print:hidden"
            >
                {pinned.map((destination) => (
                    <NavigationControl
                        key={destination.id}
                        destination={destination}
                        href={hrefFor(destination.id)}
                        active={destination.id === activeId}
                        variant="compact"
                    />
                ))}
                <button
                    type="button"
                    aria-haspopup="dialog"
                    aria-expanded={mobileMenuOpen}
                    onClick={() => setMobileMenuOpen(true)}
                    className={`flex min-w-0 flex-col items-center justify-center gap-1 rounded-[14px] text-[10px] font-semibold ${focusRing} ${activeInMenu ? 'bg-white text-[#111111]' : 'text-white/70'}`}
                >
                    <Menu className="size-[18px]" aria-hidden="true" />
                    More
                </button>
            </nav>

            <Dialog open={mobileMenuOpen} onOpenChange={setMobileMenuOpen}>
                <DialogContent className="owner-surface top-auto bottom-0 max-h-[88dvh] w-full max-w-none translate-y-0 rounded-t-[20px] rounded-b-none border-0 bg-[#111111] p-0 text-white sm:max-w-none md:hidden [&>button]:top-3 [&>button]:right-3 [&>button]:text-white">
                    <DialogHeader className="border-b border-white/10 px-4 py-4 text-left">
                        <DialogTitle className="text-base font-semibold">
                            {workspaceLabel} navigation
                        </DialogTitle>
                        <DialogDescription className="text-xs text-white/60">
                            {currentScope}
                        </DialogDescription>
                    </DialogHeader>
                    <nav className="owner-hide-scrollbar overflow-y-auto px-3 pb-[max(18px,env(safe-area-inset-bottom))]">
                        {groups.map(({ section, destinations: items }) => (
                            <div key={section.id} className="pt-4">
                                <SectionHeading label={section.label} touch />
                                <div className="flex flex-col gap-1">
                                    {items.map((destination) => (
                                        <NavigationControl
                                            key={destination.id}
                                            destination={destination}
                                            href={hrefFor(destination.id)}
                                            active={destination.id === activeId}
                                            variant="full"
                                            onNavigate={() =>
                                                setMobileMenuOpen(false)
                                            }
                                        />
                                    ))}
                                </div>
                            </div>
                        ))}
                        <Link
                            href={logout()}
                            method="post"
                            as="button"
                            className={`mt-4 flex min-h-12 w-full items-center justify-center rounded-xl border border-white/15 text-sm font-semibold text-white ${focusRing}`}
                        >
                            Log out
                        </Link>
                    </nav>
                </DialogContent>
            </Dialog>
        </div>
    );
}
