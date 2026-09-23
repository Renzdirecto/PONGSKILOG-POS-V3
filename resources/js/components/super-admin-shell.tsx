import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Boxes,
    ChefHat,
    ChevronDown,
    ClipboardList,
    Gauge,
    LayoutDashboard,
    Menu,
    MonitorUp,
    PackageSearch,
    QrCode,
    ReceiptText,
    Settings,
    ShieldBan,
    ShieldCheck,
    Store,
    UserRound,
    Users,
    UtensilsCrossed,
    type LucideIcon,
} from 'lucide-react';
import { useState } from 'react';
import { BranchSwitcher } from '@/components/branch-switcher';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    activeSuperAdminDestination,
    initialExpandedSuperAdminSections,
    superAdminNavigation,
    superAdminPinnedDestinations,
    superAdminSectionOf,
    toggleSuperAdminSection,
    withActiveSuperAdminSection,
    type SuperAdminDestination,
    type SuperAdminDestinationId,
    type SuperAdminSectionId,
} from '@/lib/super-admin-navigation';
import { logout } from '@/routes';
import { index as branchesIndex } from '@/routes/branches';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as productsIndex } from '@/routes/products';
import { edit as editProfile } from '@/routes/profile';
import { accessControl, notifications, reports } from '@/routes/super-admin';
import { index as staffIndex } from '@/routes/super-admin/staff';
import {
    auditTrail,
    cashier,
    cashierDashboard,
    customerDisplay,
    kitchen,
    owner,
    superAdmin,
    transactionHistory,
    voidOrders,
} from '@/routes/workspaces';
import type { Auth, BranchContext } from '@/types';

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
    workspace?: string;
    destination?: string;
};

type DestinationBinding = {
    icon: LucideIcon;
    href: ReturnType<typeof superAdmin>;
};

/** Every registry destination must bind to a real Wayfinder route and an icon. */
const destinationBindings: Record<SuperAdminDestinationId, DestinationBinding> =
    {
        dashboard: { icon: LayoutDashboard, href: superAdmin() },
        notifications: { icon: Bell, href: notifications() },
        'cashier-dashboard': { icon: Gauge, href: cashierDashboard() },
        pos: { icon: UtensilsCrossed, href: cashier() },
        'qr-orders': { icon: QrCode, href: cashier({ query: { view: 'qr' } }) },
        'transaction-history': {
            icon: ReceiptText,
            href: transactionHistory(),
        },
        kitchen: { icon: ChefHat, href: kitchen() },
        'customer-display': { icon: MonitorUp, href: customerDisplay() },
        'owner-dashboard': { icon: Store, href: owner() },
        reports: { icon: BarChart3, href: reports() },
        products: { icon: Boxes, href: productsIndex() },
        inventory: { icon: PackageSearch, href: inventoryIndex() },
        'audit-trail': { icon: ClipboardList, href: auditTrail() },
        'void-orders': { icon: ShieldBan, href: voidOrders() },
        staff: { icon: Users, href: staffIndex() },
        'access-control': { icon: ShieldCheck, href: accessControl() },
        settings: { icon: Settings, href: branchesIndex() },
    };

const branchRequiredReason =
    'Choose a Branch from the header to open this workspace.';

function initials(name?: string): string {
    return (name ?? 'Super Admin')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function DestinationControl({
    destination,
    active,
    hasBranch,
    compact = false,
    onNavigate,
}: {
    destination: SuperAdminDestination;
    active: boolean;
    hasBranch: boolean;
    compact?: boolean;
    onNavigate?: () => void;
}) {
    const { icon: Icon, href } = destinationBindings[destination.id];
    const blocked = destination.requiresBranch && !hasBranch;
    const className = compact
        ? `relative flex h-[70px] w-full flex-col items-center justify-center gap-1.5 rounded-xl px-1 text-center text-[10px] leading-tight font-semibold transition focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${active ? 'bg-white text-[#111111]' : 'text-white/70 hover:bg-white/10 hover:text-white'}`
        : `flex min-h-11 w-full items-center gap-3 rounded-[10px] px-3 text-left text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none min-[1180px]:min-h-[42px] ${active ? 'bg-white font-semibold text-[#111111]' : 'text-white/70 hover:bg-white/10 hover:text-white'}`;
    const content = (
        <>
            <Icon className="size-[18px] shrink-0" aria-hidden="true" />
            <span className={compact ? '' : 'min-w-0 flex-1 truncate'}>
                {compact ? destination.shortLabel : destination.label}
            </span>
            {!compact && blocked && (
                <span className="text-[9px] font-semibold tracking-[0.06em] uppercase">
                    Branch
                </span>
            )}
            {!compact && !blocked && destination.availability === 'planned' && (
                <span
                    className={`rounded-full border px-1.5 py-0.5 text-[9px] font-semibold tracking-[0.06em] uppercase ${active ? 'border-[#d4d4d4] text-[#666]' : 'border-white/20 text-white/50'}`}
                >
                    Planned
                </span>
            )}
        </>
    );

    if (blocked) {
        return (
            <button
                type="button"
                disabled
                title={branchRequiredReason}
                aria-label={`${destination.label}. ${branchRequiredReason}`}
                className={`${className} cursor-not-allowed opacity-45`}
            >
                {content}
            </button>
        );
    }

    return (
        <Link
            href={href}
            aria-current={active ? 'page' : undefined}
            className={className}
            onClick={onNavigate}
        >
            {content}
        </Link>
    );
}

function CollapsibleNavigation({
    groups,
    activeId,
    expanded,
    onToggle,
    hasBranch,
    idPrefix,
    touch = false,
    onNavigate,
}: {
    groups: ReturnType<typeof superAdminNavigation>;
    activeId: SuperAdminDestinationId | null;
    expanded: readonly SuperAdminSectionId[];
    onToggle: (section: SuperAdminSectionId) => void;
    hasBranch: boolean;
    idPrefix: string;
    touch?: boolean;
    onNavigate?: () => void;
}) {
    return (
        <>
            {groups.map(({ section, destinations }) => {
                const isExpanded = expanded.includes(section.id);
                const regionId = `${idPrefix}-${section.id}`;
                const containsActive = destinations.some(
                    (destination) => destination.id === activeId,
                );
                const needsBranch =
                    !hasBranch &&
                    destinations.some(
                        (destination) => destination.requiresBranch,
                    );

                return (
                    <div key={section.id} className="mb-1.5">
                        <button
                            type="button"
                            aria-expanded={isExpanded}
                            aria-controls={regionId}
                            onClick={() => onToggle(section.id)}
                            className={`flex w-full items-center gap-2 rounded-lg px-2.5 text-left text-[10px] font-semibold tracking-[0.1em] uppercase transition hover:bg-white/5 hover:text-white/70 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${touch ? 'min-h-11' : 'min-h-9'} ${containsActive ? 'text-white/70' : 'text-white/40'}`}
                        >
                            <span className="min-w-0 flex-1 truncate">
                                {section.label}
                            </span>
                            {!isExpanded && containsActive && (
                                <span
                                    aria-hidden="true"
                                    className="size-1.5 rounded-full bg-white"
                                />
                            )}
                            <ChevronDown
                                aria-hidden="true"
                                className={`size-3.5 shrink-0 transition-transform duration-150 ${isExpanded ? '' : '-rotate-90'}`}
                            />
                        </button>
                        <div
                            id={regionId}
                            hidden={!isExpanded}
                            className="flex flex-col gap-0.5 pt-0.5 pb-2"
                        >
                            {needsBranch && (
                                <p className="px-3 pb-1 text-[11px] leading-4 text-white/45">
                                    Choose a Branch to open these workspaces.
                                </p>
                            )}
                            {destinations.map((destination) => (
                                <DestinationControl
                                    key={destination.id}
                                    destination={destination}
                                    active={destination.id === activeId}
                                    hasBranch={hasBranch}
                                    onNavigate={onNavigate}
                                />
                            ))}
                        </div>
                    </div>
                );
            })}
        </>
    );
}

export function SuperAdminShell({ children }: { children: React.ReactNode }) {
    const page = usePage<SharedProps>();
    const { auth, branchContext } = page.props;
    const [menuOpen, setMenuOpen] = useState(false);
    const activeId = activeSuperAdminDestination({
        component: page.component,
        url: page.url,
        workspace: page.props.workspace,
        destination: page.props.destination,
    });
    const activeSection = superAdminSectionOf(activeId);
    const [expanded, setExpanded] = useState<SuperAdminSectionId[]>(() =>
        initialExpandedSuperAdminSections(activeSection),
    );
    const [expandedForSection, setExpandedForSection] = useState(activeSection);

    if (expandedForSection !== activeSection) {
        setExpandedForSection(activeSection);
        setExpanded((current) =>
            withActiveSuperAdminSection(current, activeSection),
        );
    }

    const groups = superAdminNavigation(auth.permissions);
    const destinations = groups.flatMap((group) => group.destinations);
    const pinned = superAdminPinnedDestinations
        .map((id) => destinations.find((destination) => destination.id === id))
        .filter((destination) => destination !== undefined);
    const activeIsPinned = pinned.some(
        (destination) => destination.id === activeId,
    );
    const activeDestination = destinations.find(
        (destination) => destination.id === activeId,
    );
    const hasBranch = branchContext.current !== null;
    const currentScope = branchContext.current
        ? `${branchContext.current.name} · ${branchContext.current.code}`
        : 'All Branches';
    const toggleSection = (section: SuperAdminSectionId) =>
        setExpanded((current) => toggleSuperAdminSection(current, section));
    const menuButtonClass = (compact: boolean) =>
        compact
            ? `flex h-[70px] w-full flex-col items-center justify-center gap-1.5 rounded-xl px-1 text-[10px] font-semibold transition focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${!activeIsPinned && activeId !== null ? 'bg-white text-[#111111]' : 'text-white/70 hover:bg-white/10 hover:text-white'}`
            : `flex min-w-0 flex-col items-center justify-center gap-1 rounded-[14px] text-[10px] font-semibold focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${!activeIsPinned && activeId !== null ? 'bg-white text-[#111111]' : 'text-white/70'}`;

    return (
        <div className="owner-surface flex h-dvh overflow-hidden bg-[#111111] text-[#111111]">
            <aside className="hidden w-[248px] shrink-0 flex-col bg-[#111111] min-[1180px]:flex">
                <Link
                    href={superAdmin()}
                    className="flex h-[72px] shrink-0 items-center border-b border-white/10 px-4 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none focus-visible:ring-inset"
                >
                    <img
                        src="/images/branding/logo.png"
                        alt="PONGSKILOG"
                        className="w-[168px]"
                    />
                </Link>
                <div className="border-b border-white/10 p-3">
                    <div className="flex h-14 items-center gap-3 rounded-xl border border-white/20 bg-white/10 px-3">
                        <span className="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-white/10 text-white">
                            <ShieldCheck className="size-[18px]" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-[10px] font-semibold tracking-[0.1em] text-white/40 uppercase">
                                Super Admin
                            </span>
                            <span className="block truncate text-sm font-semibold text-white">
                                Control Center
                            </span>
                        </span>
                    </div>
                </div>
                <nav
                    aria-label="Super Admin navigation"
                    className="owner-hide-scrollbar flex-1 overflow-y-auto px-2.5 py-3"
                >
                    <CollapsibleNavigation
                        groups={groups}
                        activeId={activeId}
                        expanded={expanded}
                        onToggle={toggleSection}
                        hasBranch={hasBranch}
                        idPrefix="super-admin-sidebar"
                    />
                </nav>
                <div className="border-t border-white/10 p-3">
                    <div className="flex min-h-14 items-center gap-3 rounded-[10px] px-3 text-white">
                        <span className="flex size-[34px] shrink-0 items-center justify-center rounded-full bg-white/12 text-xs font-semibold">
                            {initials(auth.user?.name)}
                        </span>
                        <span className="min-w-0 flex-1">
                            <span className="block truncate text-[13px] font-semibold">
                                {auth.user?.name}
                            </span>
                            <span className="block text-[11px] text-white/60">
                                Super Admin
                            </span>
                        </span>
                        <Link
                            href={logout()}
                            method="post"
                            as="button"
                            className="min-h-11 rounded-lg px-2 text-[11px] font-semibold text-white/70 hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        >
                            Log out
                        </Link>
                    </div>
                </div>
            </aside>

            <aside className="hidden w-24 shrink-0 flex-col bg-[#111111] min-[1180px]:hidden! md:flex">
                <Link
                    href={superAdmin()}
                    className="flex h-[82px] flex-col items-center justify-center gap-1 border-b border-white/10 px-2 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none focus-visible:ring-inset"
                >
                    <img
                        src="/images/branding/logo.png"
                        alt="PONGSKILOG"
                        className="max-w-[70px]"
                    />
                    <span className="text-[9px] font-bold tracking-[0.08em] text-white/60 uppercase">
                        Admin
                    </span>
                </Link>
                <p className="px-1.5 pt-2 text-center text-[9px] font-semibold tracking-[0.06em] text-white/40 uppercase">
                    {branchContext.current?.code ?? 'All branches'}
                </p>
                <nav
                    aria-label="Super Admin quick navigation"
                    className="owner-hide-scrollbar flex flex-1 flex-col gap-1.5 overflow-y-auto px-2 py-2"
                >
                    {pinned.map((destination) => (
                        <DestinationControl
                            key={destination.id}
                            destination={destination}
                            active={destination.id === activeId}
                            hasBranch={hasBranch}
                            compact
                        />
                    ))}
                    <button
                        type="button"
                        aria-haspopup="dialog"
                        aria-expanded={menuOpen}
                        onClick={() => setMenuOpen(true)}
                        className={menuButtonClass(true)}
                    >
                        <Menu className="size-[18px]" aria-hidden="true" />
                        {!activeIsPinned && activeDestination
                            ? activeDestination.shortLabel
                            : 'Menu'}
                    </button>
                </nav>
                <div className="flex flex-col items-center border-t border-white/10 p-2.5">
                    <Link
                        href={logout()}
                        method="post"
                        as="button"
                        aria-label="Log out"
                        title="Log out"
                        className="flex size-11 items-center justify-center rounded-full bg-white/12 text-xs font-semibold text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                    >
                        {initials(auth.user?.name)}
                    </Link>
                </div>
            </aside>

            <div className="flex min-w-0 flex-1 flex-col overflow-hidden bg-white">
                <header className="flex h-[60px] shrink-0 items-center gap-2.5 border-b border-[#e5e5e5] bg-white px-3 md:h-[72px] md:gap-3.5 md:px-5">
                    <img
                        src="/images/branding/logo.png"
                        alt="PONGSKILOG"
                        className="w-[92px] shrink-0 md:hidden"
                    />
                    <p className="min-w-0 flex-1 truncate text-base font-semibold tracking-[-0.01em] md:hidden">
                        {activeDestination?.label ?? 'Super Admin'}
                    </p>
                    <div className="hidden min-w-0 flex-1 md:block">
                        <BranchSwitcher branchContext={branchContext} />
                    </div>
                    <div className="md:hidden">
                        <BranchSwitcher branchContext={branchContext} compact />
                    </div>
                    <div className="hidden min-w-0 flex-1 text-right md:block">
                        <p className="truncate text-[13px] font-semibold">
                            {auth.user?.name}
                        </p>
                        <p className="truncate text-[11px] text-neutral-500">
                            Super Admin · {currentScope}
                        </p>
                    </div>
                    <Link
                        href={notifications()}
                        aria-label="Notifications"
                        title="Notifications"
                        aria-current={
                            activeId === 'notifications' ? 'page' : undefined
                        }
                        className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-[#e5e5e5] bg-white text-[#555] hover:border-[#bbb] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                    >
                        <Bell className="size-[18px]" />
                    </Link>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Open account menu"
                                title="Account"
                                className="flex size-11 shrink-0 items-center justify-center rounded-full bg-[#111] text-xs font-bold text-white focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:ring-offset-2 focus-visible:outline-none"
                            >
                                {initials(auth.user?.name)}
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
                                    Super Admin · {currentScope}
                                </span>
                            </DropdownMenuLabel>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem asChild>
                                <Link href={editProfile()} className="min-h-10">
                                    <UserRound className="size-4" /> Account
                                    profile
                                </Link>
                            </DropdownMenuItem>
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
                <main className="owner-scrollbar flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto bg-[#f7f7f7] pb-[calc(92px+env(safe-area-inset-bottom,0px))] md:pb-0">
                    {children}
                </main>
            </div>

            <nav
                aria-label="Mobile Super Admin navigation"
                className="fixed right-3 bottom-[max(12px,env(safe-area-inset-bottom))] left-3 z-40 mx-auto grid h-[68px] max-w-[430px] grid-cols-4 gap-1 rounded-[20px] bg-[#111111] p-1.5 shadow-2xl md:hidden"
            >
                {pinned.map((destination) => (
                    <DestinationControl
                        key={destination.id}
                        destination={destination}
                        active={destination.id === activeId}
                        hasBranch={hasBranch}
                        compact
                    />
                ))}
                <button
                    type="button"
                    aria-haspopup="dialog"
                    aria-expanded={menuOpen}
                    onClick={() => setMenuOpen(true)}
                    className={menuButtonClass(false)}
                >
                    <Menu className="size-[18px]" aria-hidden="true" />
                    More
                </button>
            </nav>

            <Dialog open={menuOpen} onOpenChange={setMenuOpen}>
                <DialogContent className="owner-surface top-auto bottom-0 flex max-h-[88dvh] w-full max-w-none translate-y-0 flex-col gap-0 rounded-t-[20px] rounded-b-none border-0 bg-[#111111] p-0 text-white min-[1180px]:hidden sm:max-w-none md:top-0 md:left-0 md:h-dvh md:max-h-dvh md:w-[320px] md:translate-x-0 md:rounded-none [&>button]:top-3 [&>button]:right-3 [&>button]:flex [&>button]:size-11 [&>button]:items-center [&>button]:justify-center [&>button]:text-white">
                    <DialogHeader className="shrink-0 border-b border-white/10 px-4 py-4 pr-14 text-left">
                        <DialogTitle className="text-base font-semibold">
                            Super Admin navigation
                        </DialogTitle>
                        <DialogDescription className="text-xs text-white/60">
                            {currentScope}
                        </DialogDescription>
                    </DialogHeader>
                    <nav
                        aria-label="All Super Admin destinations"
                        className="owner-hide-scrollbar min-h-0 flex-1 overflow-y-auto px-3 pt-3 pb-[max(18px,env(safe-area-inset-bottom))]"
                    >
                        <CollapsibleNavigation
                            groups={groups}
                            activeId={activeId}
                            expanded={expanded}
                            onToggle={toggleSection}
                            hasBranch={hasBranch}
                            idPrefix="super-admin-menu"
                            touch
                            onNavigate={() => setMenuOpen(false)}
                        />
                        <Link
                            href={logout()}
                            method="post"
                            as="button"
                            className="mt-3 flex min-h-12 w-full items-center justify-center rounded-xl border border-white/15 text-sm font-semibold text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        >
                            Log out
                        </Link>
                    </nav>
                </DialogContent>
            </Dialog>
        </div>
    );
}
