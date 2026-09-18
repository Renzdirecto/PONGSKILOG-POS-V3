import { Head } from '@inertiajs/react';
import { Store } from 'lucide-react';
import type { BranchSummary } from '@/types';

export default function CustomerQr({
    branch,
    store,
}: {
    branch: BranchSummary;
    store: { status: 'open' | 'closed' };
}) {
    const isOpen = store.status === 'open';

    return (
        <div className="min-h-svh bg-[#111111] px-5 py-10 text-white sm:py-16">
            <Head title={`${branch.name} · PONGSKILOG`} />
            <main className="mx-auto flex max-w-md flex-col items-center gap-8 text-center">
                <img
                    src="/images/branding/logo.png"
                    alt="PONGSKILOG"
                    className="h-auto w-40"
                />
                <div className="space-y-2">
                    <p className="text-xs font-bold tracking-[0.2em] text-[#F5A623] uppercase">
                        Welcome to PONGSKILOG
                    </p>
                    <h1 className="text-3xl font-bold wrap-break-word">
                        {branch.name}
                    </h1>
                    <p className="text-sm text-neutral-400">
                        Branch {branch.code}
                    </p>
                </div>
                <section
                    className="flex w-full flex-col items-center gap-5 rounded-3xl border border-neutral-700 bg-neutral-900 p-6 sm:p-8"
                    aria-labelledby="availability-heading"
                >
                    <span
                        className={`flex size-16 items-center justify-center rounded-full ${isOpen ? 'bg-emerald-950 text-emerald-300' : 'bg-neutral-800 text-[#F5A623]'}`}
                    >
                        <Store className="size-8" />
                    </span>
                    <h2
                        id="availability-heading"
                        className="text-2xl font-extrabold tracking-tight"
                    >
                        {isOpen ? 'STORE IS OPEN' : 'STORE IS CURRENTLY CLOSED'}
                    </h2>
                    <p className="text-sm leading-6 text-neutral-300">
                        {isOpen
                            ? 'QR ordering is not available yet. Please speak with our staff to place an order.'
                            : 'Ordering is unavailable right now. Please check back later.'}
                    </p>
                </section>
            </main>
        </div>
    );
}
