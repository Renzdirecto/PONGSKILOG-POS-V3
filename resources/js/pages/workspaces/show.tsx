import { Head, Link, usePage } from '@inertiajs/react';
import { Building2, CheckCircle2, Globe2 } from 'lucide-react';
import { CashierStore } from '@/components/cashier-store';
import type { CashierStoreState } from '@/components/cashier-store';
import { index as branchesIndex } from '@/routes/branches';
import { index as inventoryIndex } from '@/routes/inventory';
import { index as productsIndex } from '@/routes/products';
import type { Auth, BranchContext, StoreContext } from '@/types';
import type { CashierCatalog } from '@/types/catalog';

import type { BranchTable } from '@/types/pos';

type Props = {
    workspace: string;
    eyebrow: string;
    description: string;
    store?: CashierStoreState;
    catalog?: CashierCatalog;
    tables?: BranchTable[];
};

type SharedProps = {
    auth: Auth;
    branchContext: BranchContext;
    storeContext: StoreContext;
};

export default function Workspace({
    workspace,
    eyebrow,
    description,
    store,
    catalog,
    tables = [],
}: Props) {
    const { auth, branchContext, storeContext } = usePage<SharedProps>().props;
    const scope = branchContext.current?.name ?? 'All Branches';

    if (store && catalog && branchContext.current) {
        return (
            <>
                <Head title="Cashier / POS workspace" />
                <CashierStore
                    key={branchContext.current.id}
                    branch={branchContext.current}
                    store={store}
                    storeContext={storeContext}
                    catalog={catalog}
                    tables={tables}
                />
            </>
        );
    }

    return (
        <>
            <Head title={`${workspace} workspace`} />

            <div className="mx-auto max-w-5xl">
                <div className="mb-8 flex flex-col gap-5 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-3">
                        <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                            {eyebrow}
                        </p>
                        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">
                            {workspace} workspace
                        </h1>
                        <p className="max-w-2xl text-base leading-7 text-neutral-600">
                            {description}
                        </p>
                    </div>

                    <div className="flex min-h-12 items-center gap-3 rounded-xl border border-neutral-200 bg-white px-4 py-3 shadow-sm">
                        {branchContext.current ? (
                            <Building2 className="size-5 text-neutral-500" />
                        ) : (
                            <Globe2 className="size-5 text-neutral-500" />
                        )}
                        <div>
                            <p className="text-[0.62rem] font-semibold tracking-[0.14em] text-neutral-400 uppercase">
                                Current scope
                            </p>
                            <p className="text-sm font-bold">{scope}</p>
                        </div>
                    </div>
                </div>

                {auth.permissions.includes('products.manage') && (
                    <Link
                        href={productsIndex()}
                        className="mr-3 mb-6 inline-flex min-h-11 items-center rounded-xl bg-neutral-950 px-5 py-3 text-sm font-semibold text-white hover:bg-neutral-800"
                    >
                        Product management
                    </Link>
                )}
                {auth.permissions.includes('inventory.manage') && (
                    <Link
                        href={inventoryIndex()}
                        className="mr-3 mb-6 inline-flex min-h-11 items-center rounded-xl bg-neutral-950 px-5 py-3 text-sm font-semibold text-white hover:bg-neutral-800"
                    >
                        Inventory
                    </Link>
                )}
                {branchContext.businessWide &&
                    auth.permissions.includes('settings.manage') && (
                        <Link
                            href={branchesIndex()}
                            className="mb-6 inline-flex min-h-11 items-center gap-2 rounded-xl bg-neutral-950 px-5 py-3 text-sm font-semibold text-white hover:bg-neutral-800"
                        >
                            <Building2 className="size-4" /> Branch management
                        </Link>
                    )}
                <section className="rounded-3xl border border-neutral-200 bg-white p-7 shadow-sm sm:p-10">
                    <div className="flex flex-col gap-6 sm:flex-row sm:items-center">
                        <span className="flex size-14 shrink-0 items-center justify-center rounded-2xl bg-emerald-50 text-emerald-700">
                            <CheckCircle2 className="size-7" />
                        </span>
                        <div>
                            <h2 className="text-xl font-bold">
                                Workspace routing is ready
                            </h2>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-neutral-600">
                                You reached the protected {workspace} entry
                                point with the correct role and branch context.
                                The operational features for this workspace are
                                intentionally delivered in later phases.
                            </p>
                        </div>
                    </div>
                </section>
            </div>
        </>
    );
}
