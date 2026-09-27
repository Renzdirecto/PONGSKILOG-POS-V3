import { Store } from 'lucide-react';
import { orderTypeText, orderTypeTone } from '@/lib/customer-screen';
import type { CustomerDisplayData } from '@/types';

/**
 * The order-number board (Preparing / Ready for pickup): the existing Customer Display projection, shared by the
 * staff-launched display page and the paired customer screen's CUSTOMER DISPLAY mode. Order numbers only, each in its
 * order-type color (Dine In green, Take Out blue) with its DINE IN / TAKE OUT label, and a compact bottom summary of
 * the Dine In / Take Out orders in the queue — counts the server took from the same rows.
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
        <>
            <main className="grid min-h-0 flex-1 gap-px overflow-y-auto bg-white/10 min-[820px]:grid-cols-2">
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
            <QueueCounts counts={display.counts} />
        </>
    );
}

/** "Dine In: X · Take Out: Y" — the orders in the queue (the Preparing column), as counted by the server. */
function QueueCounts({ counts }: { counts: CustomerDisplayData['counts'] }) {
    return (
        <section
            aria-label="Orders in queue"
            className="flex shrink-0 flex-wrap items-center justify-center gap-x-3 gap-y-1.5 border-t border-white/10 bg-[#0c0d0d] px-4 py-2 sm:gap-x-5 sm:py-2.5"
        >
            <span className="text-[11px] font-black tracking-[0.18em] text-white/55 sm:text-xs">
                IN QUEUE
            </span>
            {(['dine_in', 'take_out'] as const).map((type) => (
                <span
                    key={type}
                    className={`inline-flex items-center gap-2 rounded-full border px-3 py-1 text-sm font-bold sm:text-lg ${orderTypeTone(type).tint}`}
                >
                    {type === 'dine_in' ? 'Dine In' : 'Take Out'}:
                    <span className="font-black tabular-nums">
                        {counts[type]}
                    </span>
                </span>
            ))}
        </section>
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
    numbers: CustomerDisplayData['preparing'];
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
                    {numbers.map(({ number, order_type }) => {
                        /** Ready numbers stand out (solid); waiting numbers are a tint of the same order-type color. */
                        const colors = orderTypeTone(order_type);

                        return (
                            <div
                                key={number}
                                className={`flex min-h-28 flex-col items-center justify-center gap-1 rounded-2xl border-2 tabular-nums ${tone === 'neutral' ? colors.tint : colors.solid}`}
                            >
                                <span className="text-[34px] leading-none font-black tracking-tight min-[620px]:text-[46px]">
                                    {number}
                                </span>
                                <span className="text-[11px] font-black tracking-[0.16em] opacity-85 min-[620px]:text-xs">
                                    {orderTypeText(order_type)}
                                </span>
                            </div>
                        );
                    })}
                </div>
            )}
        </section>
    );
}
