import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock3, Store } from 'lucide-react';
import { useEffect, useState } from 'react';
import { PwaStatus } from '@/components/pwa-status';
import { useBranchRealtimeRefresh } from '@/hooks/use-branch-realtime-refresh';
import { DISPLAY_REALTIME_EVENTS } from '@/lib/kitchen';
import { kitchen } from '@/routes/workspaces';
import type { CustomerDisplayData } from '@/types';

type Props = {
    branchId: string;
    branchName: string;
    display: CustomerDisplayData;
};

export default function CustomerDisplay({
    branchId,
    branchName,
    display,
}: Props) {
    const [clock, setClock] = useState(() => new Date());

    useBranchRealtimeRefresh({
        branchId,
        channel: 'customer-display',
        debounceMs: 35,
        events: DISPLAY_REALTIME_EVENTS,
        only: ['display'],
    });

    useEffect(() => {
        const timer = window.setInterval(() => setClock(new Date()), 1000);

        return () => window.clearInterval(timer);
    }, []);

    return (
        <>
            <Head title="Order status board" />
            <div className="flex min-h-dvh flex-col bg-[#101111] pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] text-white">
                <header className="grid min-h-[82px] grid-cols-[minmax(0,1fr)_auto] items-center gap-3 border-b border-white/10 px-4 py-4 sm:flex sm:flex-wrap sm:gap-4 sm:px-7">
                    <div className="col-span-2 flex min-w-0 items-center gap-3 sm:col-auto">
                        <img
                            src="/images/branding/logo.png"
                            alt=""
                            className="w-[72px]"
                        />
                        <span className="text-base font-black tracking-[0.12em] text-white sm:text-lg">
                            PONGSKILOG
                        </span>
                    </div>
                    <div className="col-span-2 min-w-0 sm:col-auto sm:flex-1">
                        <h1 className="text-lg font-black tracking-tight sm:text-2xl">
                            Order status board
                        </h1>
                        <p className="truncate text-xs text-white/55">
                            {branchName}
                        </p>
                    </div>
                    <div className="flex items-center gap-2 text-sm font-bold tabular-nums sm:text-lg">
                        <Clock3 className="size-4 text-white/50" />
                        {clock.toLocaleTimeString([], {
                            hour: '2-digit',
                            minute: '2-digit',
                        })}
                    </div>
                    <PwaStatus tone="dark" />
                    <Link
                        href={kitchen()}
                        className="inline-flex min-h-11 items-center justify-center gap-2 rounded-xl border border-white/15 px-3 text-xs font-bold transition hover:bg-white/10"
                    >
                        <ArrowLeft className="size-4" /> Exit display
                    </Link>
                </header>

                {!display.is_open ? (
                    <div className="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-20 text-center">
                        <Store className="size-12 text-white/30" />
                        <div>
                            <h2 className="text-3xl font-black">Store closed</h2>
                            <p className="mt-2 text-white/50">
                                Order updates will appear when service resumes.
                            </p>
                        </div>
                    </div>
                ) : (
                    <main className="grid flex-1 gap-px bg-white/10 min-[820px]:grid-cols-2">
                        <DisplayColumn
                            title="Preparing"
                            description="We’re making your order"
                            numbers={display.preparing}
                            tone="neutral"
                        />
                        <DisplayColumn
                            title="Ready for pickup"
                            description="Please collect your order"
                            numbers={display.ready}
                            tone="green"
                        />
                    </main>
                )}

                <footer className="border-t border-white/10 px-5 py-4 text-center text-sm font-semibold text-white/65 sm:text-base">
                    Please listen for your number. Salamat po!
                </footer>
            </div>
        </>
    );
}

function DisplayColumn({
    title,
    description,
    numbers,
    tone,
}: {
    title: string;
    description: string;
    numbers: string[];
    tone: 'neutral' | 'green';
}) {
    return (
        <section
            className={`min-w-0 p-5 sm:p-7 ${tone === 'neutral' ? 'bg-[#111212]' : 'bg-[#181919]'}`}
        >
            <div className="mb-6 flex items-center gap-3">
                <span
                    className={`size-3 rounded-full ${tone === 'neutral' ? 'animate-pulse bg-amber-400' : 'bg-emerald-400'}`}
                />
                <div>
                    <h2 className="text-2xl font-black sm:text-3xl">{title}</h2>
                    <p className="text-xs text-white/45 sm:text-sm">
                        {description}
                    </p>
                </div>
            </div>
            {numbers.length === 0 ? (
                <div className="flex min-h-40 items-center justify-center rounded-2xl border border-dashed border-white/10 text-sm font-semibold text-white/25">
                    No orders yet
                </div>
            ) : (
                <div className="grid grid-cols-[repeat(auto-fit,minmax(120px,1fr))] gap-3 min-[620px]:grid-cols-[repeat(auto-fit,minmax(160px,1fr))]">
                    {numbers.map((number) => (
                        <div
                            key={number}
                            className={`flex min-h-28 items-center justify-center rounded-2xl border text-[34px] font-black tracking-tight tabular-nums min-[620px]:text-[46px] ${tone === 'neutral' ? 'border-white/15 bg-white/4 text-white' : 'border-emerald-400/25 bg-emerald-900/55 text-emerald-200'}`}
                        >
                            {number}
                        </div>
                    ))}
                </div>
            )}
        </section>
    );
}
