import { Head, Link } from '@inertiajs/react';
import { ArrowLeft, Clock3 } from 'lucide-react';
import { useEffect, useState } from 'react';
import { CustomerOrderBoard } from '@/components/customer-order-board';
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

                <CustomerOrderBoard display={display} />

                <footer className="border-t border-white/10 px-5 py-4 text-center text-sm font-semibold text-white/65 sm:text-base">
                    Please listen for your number. Salamat po!
                </footer>
            </div>
        </>
    );
}
