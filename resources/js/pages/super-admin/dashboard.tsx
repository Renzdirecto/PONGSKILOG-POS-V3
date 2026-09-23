import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Building2,
    ClipboardList,
    Settings,
    ShieldBan,
    Users,
    type LucideIcon,
} from 'lucide-react';
import { OwnerPage, ownerPanelClass } from '@/components/owner-ui';
import { index as branchesIndex } from '@/routes/branches';
import { index as staffIndex } from '@/routes/super-admin/staff';
import { auditTrail, voidOrders } from '@/routes/workspaces';
import type { Auth, BranchContext } from '@/types';

type QuickLink = {
    label: string;
    description: string;
    icon: LucideIcon;
    href: ReturnType<typeof auditTrail>;
    permission: string;
};

const quickLinks: QuickLink[] = [
    {
        label: 'Staff',
        description: 'Create login accounts and review role and Branch access.',
        icon: Users,
        href: staffIndex(),
        permission: 'access_control.manage',
    },
    {
        label: 'Audit Trail',
        description: 'Review recorded business-critical activity.',
        icon: ClipboardList,
        href: auditTrail(),
        permission: 'audit.view',
    },
    {
        label: 'Void Orders',
        description: 'Review voided sales and manage the approval PIN.',
        icon: ShieldBan,
        href: voidOrders(),
        permission: 'void_orders.manage',
    },
    {
        label: 'Settings',
        description: 'Branch details, receipts, and customer QR entry points.',
        icon: Settings,
        href: branchesIndex(),
        permission: 'settings.manage',
    },
];

export default function SuperAdminDashboard() {
    const { auth, branchContext } = usePage<{
        auth: Auth;
        branchContext: BranchContext;
    }>().props;
    const links = quickLinks.filter((link) =>
        auth.permissions.includes(link.permission),
    );

    return (
        <>
            <Head title="Super Admin" />
            <OwnerPage
                title="Control Center"
                description="Full-access Super Admin workspace for staff access, business controls, and every operational workspace."
                maxWidth="max-w-[1180px]"
            >
                <div className="md:hidden">
                    <p className="text-[10px] font-semibold tracking-[0.1em] text-[#8a8a8a] uppercase">
                        Super Admin
                    </p>
                    <h1 className="text-[20px] font-bold tracking-[-0.02em]">
                        Control Center
                    </h1>
                </div>
                <ul className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {links.map(({ label, description, icon: Icon, href }) => (
                        <li key={label}>
                            <Link
                                href={href}
                                className={`${ownerPanelClass} group flex h-full min-h-[132px] flex-col justify-between gap-4 p-4 transition hover:border-[#bdbdbd] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none`}
                            >
                                <span className="flex items-center justify-between">
                                    <Icon
                                        className="size-5 text-[#666]"
                                        aria-hidden="true"
                                    />
                                    <ArrowRight
                                        className="size-4 text-[#b5b5b5] transition group-hover:text-[#111]"
                                        aria-hidden="true"
                                    />
                                </span>
                                <span>
                                    <strong className="block text-sm font-semibold">
                                        {label}
                                    </strong>
                                    <span className="mt-1 block text-[11.5px] leading-5 text-[#767676]">
                                        {description}
                                    </span>
                                </span>
                            </Link>
                        </li>
                    ))}
                </ul>
                <section
                    className={`${ownerPanelClass} flex items-start gap-3 p-4`}
                >
                    <Building2
                        className="mt-0.5 size-5 shrink-0 text-[#666]"
                        aria-hidden="true"
                    />
                    <div>
                        <h2 className="text-sm font-semibold">
                            Branch workspaces
                        </h2>
                        <p className="mt-1 text-[12.5px] leading-5 text-[#666]">
                            {branchContext.current
                                ? `Cashier Dashboard, POS, QR Orders, Transaction History, Kitchen, and Customer Display open for ${branchContext.current.name}.`
                                : 'Choose a Branch from the header to open Cashier Dashboard, POS, QR Orders, Transaction History, Kitchen, and Customer Display.'}{' '}
                            Actions there follow the same Store Session rules as
                            Cashiers and are recorded under your name.
                        </p>
                    </div>
                </section>
            </OwnerPage>
        </>
    );
}
