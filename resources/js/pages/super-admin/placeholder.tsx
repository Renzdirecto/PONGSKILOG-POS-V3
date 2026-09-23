import { Head } from '@inertiajs/react';
import { Bell, ShieldCheck, type LucideIcon } from 'lucide-react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerPanelClass,
} from '@/components/owner-ui';

type PlannedDestination = 'notifications' | 'access-control';

const content: Record<
    PlannedDestination,
    { title: string; description: string; icon: LucideIcon; detail: string }
> = {
    notifications: {
        title: 'Notifications',
        description:
            'Operational alerts and system notices will be collected here.',
        icon: Bell,
        detail: 'No notification service is connected yet, so no alerts or unread counts are shown.',
    },
    'access-control': {
        title: 'Access Control',
        description: 'Role and page access controls will be configured here.',
        icon: ShieldCheck,
        detail: 'Access is currently fixed by each role. Editing page access will arrive with backend-enforced permissions, so nothing here can be changed yet.',
    },
};

const roleAccess: {
    role: string;
    access: string;
    tone: 'green' | 'outline';
}[] = [
    { role: 'Super Admin', access: 'Locked · Full access', tone: 'green' },
    { role: 'Owner', access: 'Editable later', tone: 'outline' },
    { role: 'Cashier', access: 'Editable later', tone: 'outline' },
    { role: 'Kitchen Staff', access: 'Editable later', tone: 'outline' },
    {
        role: 'Cashier + Kitchen',
        access: 'Derived from Cashier and Kitchen Staff',
        tone: 'outline',
    },
];

export default function SuperAdminPlaceholder({
    destination,
}: {
    destination: PlannedDestination;
}) {
    const page = content[destination];
    const Icon = page.icon;

    return (
        <>
            <Head title={page.title} />
            <OwnerPage
                title={page.title}
                description={page.description}
                maxWidth="max-w-[880px]"
            >
                <section
                    className={`${ownerPanelClass} flex flex-col gap-4 p-5 sm:flex-row sm:items-start`}
                >
                    <span className="flex size-11 shrink-0 items-center justify-center rounded-xl bg-[#f3f3f3] text-[#555]">
                        <Icon className="size-5" aria-hidden="true" />
                    </span>
                    <div className="min-w-0 flex-1">
                        <div className="flex flex-wrap items-center gap-2">
                            <h2 className="text-[15px] font-semibold md:hidden">
                                {page.title}
                            </h2>
                            <OwnerStatusBadge tone="amber">
                                Planned
                            </OwnerStatusBadge>
                        </div>
                        <p className="mt-2 text-[12.5px] leading-5 text-[#666] md:hidden">
                            {page.description}
                        </p>
                        <p className="mt-2 text-[12.5px] leading-5 text-[#444]">
                            {page.detail}
                        </p>
                    </div>
                </section>

                {destination === 'access-control' && (
                    <section className={`${ownerPanelClass} p-5`}>
                        <h2 className="text-sm font-semibold">
                            Current role groups
                        </h2>
                        <p className="mt-1 text-[12px] leading-5 text-[#767676]">
                            Read-only overview. These are not controls.
                        </p>
                        <ul className="mt-4 divide-y divide-[#eeeeee] border-y border-[#eeeeee]">
                            {roleAccess.map(({ role, access, tone }) => (
                                <li
                                    key={role}
                                    className="flex flex-wrap items-center justify-between gap-2 py-3"
                                >
                                    <span className="text-[13px] font-semibold">
                                        {role}
                                    </span>
                                    <OwnerStatusBadge tone={tone}>
                                        {access}
                                    </OwnerStatusBadge>
                                </li>
                            ))}
                        </ul>
                    </section>
                )}
            </OwnerPage>
        </>
    );
}
