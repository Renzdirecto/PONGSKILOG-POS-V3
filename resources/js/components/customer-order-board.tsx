import { Store } from 'lucide-react';
import type { CustomerDisplayData } from '@/types';

/**
 * The order-number board (Preparing / Ready for pickup): the existing Customer Display projection, shared by the
 * staff-launched display page and the paired customer screen's CUSTOMER DISPLAY mode. Order numbers only.
 */
export function CustomerOrderBoard({
    display,
}: {
    display: CustomerDisplayData;
}) {
    if (!display.is_open) {
        return (
            <div className="flex flex-1 flex-col items-center justify-center gap-4 px-6 py-20 text-center">
                <Store className="size-12 text-white/30" />
                <div>
                    <h2 className="text-3xl font-black">Store closed</h2>
                    <p className="mt-2 text-white/50">
                        Order updates will appear when service resumes.
                    </p>
                </div>
            </div>
        );
    }

    return (
        <main className="grid flex-1 gap-px overflow-y-auto bg-white/10 min-[820px]:grid-cols-2">
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
