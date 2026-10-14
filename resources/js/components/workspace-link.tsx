import { Link, router, usePage } from '@inertiajs/react';
import type { ComponentProps } from 'react';
import { instantWorkspaceProps } from '@/lib/workspace-navigation';
import {
    cashier,
    cashierDashboard,
    kitchen,
    reports,
    transactionHistory,
    transactions,
    owner,
} from '@/routes/workspaces';

const destinations = [
    {
        path: cashier.url(),
        component: 'workspaces/show',
        pageProps: { workspace: 'Cashier / POS' },
    },
    { path: kitchen.url(), component: 'workspaces/kitchen' },
    {
        path: transactionHistory.url(),
        component: 'workspaces/transaction-history',
        pageProps: { surface: 'pos' },
    },
    {
        path: transactions.url(),
        component: 'workspaces/transaction-history',
        pageProps: { surface: 'business' },
    },
    { path: reports.url(), component: 'workspaces/reports' },
    { path: cashierDashboard.url(), component: 'workspaces/cashier-dashboard' },
    { path: owner.url(), component: 'workspaces/owner-dashboard' },
];

export function WorkspaceLink(props: ComponentProps<typeof Link>) {
    const href = typeof props.href === 'object' ? props.href.url : props.href;
    const target = destinations.find(
        ({ path }) => path === href?.split('?')[0],
    );
    const sameComponent = target?.component === usePage().component;
    return (
        <Link
            {...props}
            component={sameComponent ? undefined : target?.component}
            pageProps={(_current, shared) =>
                instantWorkspaceProps(shared, target?.pageProps)
            }
            onBefore={(visit) => {
                const result = props.onBefore?.(visit);
                if (result === false) return false;
                const cached = props.href ? router.getCached(props.href) : null;
                if (
                    cached &&
                    !cached.inFlight &&
                    cached.expiresAt > Date.now()
                ) {
                    visit.component = null;
                }
                return result;
            }}
            prefetch={['hover', 'click']}
            cacheFor={1000}
            cacheTags="workspaces"
        />
    );
}
