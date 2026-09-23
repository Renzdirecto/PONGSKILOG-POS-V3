import { Link, usePage } from '@inertiajs/react';
import {
    BarChart3,
    Bell,
    Boxes,
    LayoutDashboard,
    Menu,
    PackageSearch,
    ReceiptText,
    Settings,
    UserRound,
    Users,
} from 'lucide-react';
import { useState } from 'react';
import { BranchSwitcher } from '@/components/branch-switcher';
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
import { index as branchesIndex } from '@/routes/branches';
import { index as inventoryIndex } from '@/routes/inventory';
import { logout } from '@/routes';
import { edit as editProfile } from '@/routes/profile';
import { index as productsIndex } from '@/routes/products';
import { owner, reports } from '@/routes/workspaces';
import type { Auth, BranchContext } from '@/types';

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
    workspace?: string;
};

type NavigationItem = {
    label: string;
    shortLabel: string;
    icon: typeof LayoutDashboard;
    href?: ReturnType<typeof owner>;
    active: boolean;
    unavailableReason?: string;
};

function initials(name?: string): string {
    return (name ?? 'Owner')
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0])
        .join('')
        .toUpperCase();
}

function NavigationControl({
    item,
    compact = false,
    onNavigate,
}: {
    item: NavigationItem;
    compact?: boolean;
    onNavigate?: () => void;
}) {
    const Icon = item.icon;
    const className = compact
        ? `relative flex h-[70px] w-full flex-col items-center justify-center gap-1.5 rounded-xl px-1 text-center text-[10px] font-semibold leading-tight transition focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${item.active ? 'bg-white text-[#111111]' : 'text-white/70 hover:bg-white/10 hover:text-white'}`
        : `flex h-[46px] w-full items-center gap-3 rounded-[10px] px-3 text-left text-sm font-medium transition focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${item.active ? 'bg-white font-semibold text-[#111111]' : 'text-white/70 hover:bg-white/10 hover:text-white'}`;

    if (!item.href) {
        return (
            <button
                type="button"
                disabled
                title={item.unavailableReason}
                className={`${className} cursor-not-allowed opacity-45`}
            >
                <Icon className="size-[18px] shrink-0" />
                <span className={compact ? '' : 'min-w-0 flex-1 truncate'}>
                    {compact ? item.shortLabel : item.label}
                </span>
                {!compact && (
                    <span className="text-[9px] font-semibold tracking-[0.06em] uppercase">
                        Later
                    </span>
                )}
            </button>
        );
    }

    return (
        <Link
            href={item.href}
            aria-current={item.active ? 'page' : undefined}
            className={className}
            onClick={onNavigate}
        >
            <Icon className="size-[18px] shrink-0" />
            <span className={compact ? '' : 'min-w-0 flex-1 truncate'}>
                {compact ? item.shortLabel : item.label}
            </span>
        </Link>
    );
}

export function OwnerWorkspaceShell({
    children,
}: {
    children: React.ReactNode;
}) {
    const page = usePage<SharedProps>();
    const { auth, branchContext } = page.props;
    const [mobileMenuOpen, setMobileMenuOpen] = useState(false);
    /** Super Admin uses the dedicated collapsible SuperAdminShell; this shell is Owner-only. */
    const workspaceLabel = 'Owner';
    const dashboardRoute = owner();
    const isCatalog = page.component.startsWith('catalog/');
    const isInventory = page.component.startsWith('inventory/');
    const isBranches = page.component === 'branches/index';
    const isReports = page.component === 'workspaces/reports';
    const isDashboard =
        page.component === 'workspaces/show' && !isCatalog && !isInventory;
    const canProducts = auth.permissions.includes('products.manage');
    const canInventory = auth.permissions.includes('inventory.manage');
    const canSettings =
        branchContext.businessWide &&
        auth.permissions.includes('settings.manage');
    const navigation: { label: string; items: NavigationItem[] }[] = [
        {
            label: 'Overview',
            items: [
                {
                    label: 'Dashboard',
                    shortLabel: 'Home',
                    icon: LayoutDashboard,
                    href: dashboardRoute,
                    active: isDashboard,
                },
            ],
        },
        {
            label: 'Sales',
            items: [
                {
                    label: 'Transactions',
                    shortLabel: 'Sales',
                    icon: ReceiptText,
                    active: false,
                    unavailableReason:
                        'Transactions will be available in Phase 12.',
                },
                {
                    label: 'Reports',
                    shortLabel: 'Reports',
                    icon: BarChart3,
                    href: reports(),
                    active: isReports,
                },
            ],
        },
        {
            label: 'Catalog',
            items: [
                {
                    label: 'Products',
                    shortLabel: 'Products',
                    icon: Boxes,
                    href: canProducts ? productsIndex() : undefined,
                    active: isCatalog,
                    unavailableReason: 'Product management is unavailable.',
                },
                {
                    label: 'Inventory',
                    shortLabel: 'Stock',
                    icon: PackageSearch,
                    href: canInventory ? inventoryIndex() : undefined,
                    active: isInventory,
                    unavailableReason: 'Inventory management is unavailable.',
                },
            ],
        },
        {
            label: 'Business',
            items: [
                {
                    label: 'Staff',
                    shortLabel: 'Staff',
                    icon: Users,
                    active: false,
                    unavailableReason:
                        'Staff management is outside this refinement scope.',
                },
                {
                    label: 'Settings',
                    shortLabel: 'Settings',
                    icon: Settings,
                    href: canSettings ? branchesIndex() : undefined,
                    active: isBranches,
                    unavailableReason: 'Business settings are unavailable.',
                },
            ],
        },
    ];
    const navigationItems = navigation.flatMap((group) => group.items);
    const mobileItems = [
        navigationItems[0],
        navigationItems.find((item) => item.label === 'Products')!,
        navigationItems.find((item) => item.label === 'Inventory')!,
    ];
    const pageTitle = isCatalog
        ? 'Products'
        : isInventory
          ? 'Inventory'
          : isBranches
            ? 'Branch management'
            : isReports
              ? 'Reports'
              : `${workspaceLabel} workspace`;
    const currentScope = branchContext.current
        ? `${branchContext.current.name} · ${branchContext.current.code}`
        : 'All Branches';

    return (
        <div className="owner-surface flex h-dvh overflow-hidden bg-[#111111] text-[#111111]">
            <aside className="hidden w-[248px] shrink-0 flex-col bg-[#111111] min-[1180px]:flex">
                <div className="flex h-[72px] shrink-0 items-center border-b border-white/10 px-4">
                    <img
                        src="/images/branding/logo.png"
                        alt="PONGSKILOG"
                        className="w-[168px]"
                    />
                </div>
                <div className="border-b border-white/10 p-3">
                    <div className="flex h-14 items-center gap-3 rounded-xl border border-white/20 bg-white/10 px-3">
                        <span className="flex size-[34px] shrink-0 items-center justify-center rounded-[9px] bg-white/10 text-white">
                            <LayoutDashboard className="size-[18px]" />
                        </span>
                        <span className="min-w-0">
                            <span className="block text-[10px] font-semibold tracking-[0.1em] text-white/40 uppercase">
                                Workspace
                            </span>
                            <span className="block truncate text-sm font-semibold text-white">
                                {workspaceLabel}
                            </span>
                        </span>
                    </div>
                </div>
                <nav
                    aria-label={`${workspaceLabel} navigation`}
                    className="owner-hide-scrollbar flex-1 overflow-y-auto px-2.5 py-3.5"
                >
                    {navigation.map((group) => (
                        <div key={group.label} className="mb-4">
                            <p className="px-2.5 pb-2 text-[10px] font-semibold tracking-[0.1em] text-white/40 uppercase">
                                {group.label}
                            </p>
                            <div className="flex flex-col gap-0.5">
                                {group.items.map((item) => (
                                    <NavigationControl
                                        key={item.label}
                                        item={item}
                                    />
                                ))}
                            </div>
                        </div>
                    ))}
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
                                {workspaceLabel}
                            </span>
                        </span>
                        <Link
                            href={logout()}
                            method="post"
                            as="button"
                            className="rounded-lg px-2 py-2 text-[11px] font-semibold text-white/70 hover:bg-white/10 hover:text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        >
                            Log out
                        </Link>
                    </div>
                </div>
            </aside>

            <aside className="hidden w-24 shrink-0 flex-col bg-[#111111] min-[1180px]:hidden! md:flex">
                <Link
                    href={dashboardRoute}
                    className="flex h-[82px] flex-col items-center justify-center gap-1 border-b border-white/10 px-2 focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none focus-visible:ring-inset"
                >
                    <img
                        src="/images/branding/logo.png"
                        alt="PONGSKILOG"
                        className="max-w-[70px]"
                    />
                    <span className="text-[9px] font-bold tracking-[0.08em] text-white/60 uppercase">
                        Owner
                    </span>
                </Link>
                <p className="px-1.5 pt-2 text-center text-[9px] font-semibold tracking-[0.06em] text-white/40 uppercase">
                    {branchContext.current?.code ?? 'All branches'}
                </p>
                <nav
                    aria-label={`${workspaceLabel} navigation`}
                    className="owner-hide-scrollbar flex flex-1 flex-col gap-1.5 overflow-y-auto px-2 py-2"
                >
                    {navigationItems.map((item) => (
                        <NavigationControl
                            key={item.label}
                            item={item}
                            compact
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
                        {pageTitle}
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
                            {workspaceLabel} · {currentScope}
                        </p>
                    </div>
                    <DropdownMenu>
                        <DropdownMenuTrigger asChild>
                            <button
                                type="button"
                                aria-label="Notifications"
                                title="Notifications"
                                className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-[#e5e5e5] bg-white text-[#555] hover:border-[#bbb] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                            >
                                <Bell className="size-[18px]" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            align="end"
                            className="owner-surface w-[min(310px,calc(100vw-24px))] rounded-xl p-2"
                        >
                            <DropdownMenuLabel className="text-[13px] font-semibold">
                                Notifications
                            </DropdownMenuLabel>
                            <p className="px-2 pb-2 text-[11.5px] leading-5 text-[#666]">
                                Notifications are coming later. No notification
                                count is shown until the real service is ready.
                            </p>
                        </DropdownMenuContent>
                    </DropdownMenu>
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
                                    {workspaceLabel} · {currentScope}
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
                <main className="owner-scrollbar relative flex min-h-0 min-w-0 flex-1 flex-col overflow-y-auto bg-[#f7f7f7] pb-[calc(92px+env(safe-area-inset-bottom,0px))] md:pb-0">
                    {children}
                </main>
            </div>

            <nav
                aria-label={`Mobile ${workspaceLabel} navigation`}
                className="fixed right-3 bottom-[max(12px,env(safe-area-inset-bottom))] left-3 z-40 mx-auto grid h-[68px] max-w-[430px] grid-cols-4 gap-1 rounded-[20px] bg-[#111111] p-1.5 shadow-2xl md:hidden"
            >
                {mobileItems.map((item) => (
                    <NavigationControl key={item.label} item={item} compact />
                ))}
                <button
                    type="button"
                    aria-haspopup="dialog"
                    aria-expanded={mobileMenuOpen}
                    onClick={() => setMobileMenuOpen(true)}
                    className={`flex min-w-0 flex-col items-center justify-center gap-1 rounded-[14px] text-[10px] font-semibold focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none ${isBranches || isReports ? 'bg-white text-[#111111]' : 'text-white/70'}`}
                >
                    <Menu className="size-[18px]" />
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
                        {navigation.map((group) => (
                            <div key={group.label} className="pt-4">
                                <p className="px-3 pb-2 text-[10px] font-semibold tracking-[0.1em] text-white/40 uppercase">
                                    {group.label}
                                </p>
                                <div className="flex flex-col gap-1">
                                    {group.items.map((item) => (
                                        <NavigationControl
                                            key={item.label}
                                            item={item}
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
                            className="mt-4 flex min-h-12 w-full items-center justify-center rounded-xl border border-white/15 text-sm font-semibold text-white focus-visible:ring-2 focus-visible:ring-white focus-visible:outline-none"
                        >
                            Log out
                        </Link>
                    </nav>
                </DialogContent>
            </Dialog>
        </div>
    );
}
