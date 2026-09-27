import { CheckCircle2, QrCode } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import {
    orderTypeText,
    overallPositionText,
    TAKEOVER_READY_TIMEOUT_MS,
    takeoverWaitsForQr,
    typeQueueLabel,
    type CustomerScreenQueueRow,
    type CustomerScreenTakeover as Takeover,
} from '@/lib/customer-screen';

/**
 * The dedicated order confirmation (Phase 19.6) shown above everything after a Dine In or Take Out order is committed.
 * It is its own layout — not the Customer Display board: the customer's large green order number and type, the
 * server-derived positions (the same-type position emphasized over the overall one), a window of the active queue
 * with the customer's row in green, and for Take Out the pickup QR. No customer name is ever part of it.
 *
 * `onReady` fires once the confirmation is really on screen — at once for Dine In, after the QR image rendered for
 * Take Out (or after `TAKEOVER_READY_TIMEOUT_MS` / a failed image, so it never hangs) — and only then does the page
 * start the Branch's configured countdown, so rendering the QR never eats the customer's scan time.
 */
export function CustomerScreenTakeover({
    takeover,
    showing,
    showMs,
    onReady,
}: {
    takeover: Takeover;
    showing: boolean;
    showMs: number;
    onReady: () => void;
}) {
    const waitsForQr = takeoverWaitsForQr(takeover);
    const [qrFailed, setQrFailed] = useState(false);
    const ready = useRef(false);
    const onReadyRef = useRef(onReady);
    onReadyRef.current = onReady;
    /** Once per confirmation (the component is keyed by its id). */
    const markReady = useCallback(() => {
        if (ready.current) return;
        ready.current = true;
        onReadyRef.current();
    }, []);

    useEffect(() => {
        if (!waitsForQr) {
            markReady();

            return;
        }
        const timer = window.setTimeout(markReady, TAKEOVER_READY_TIMEOUT_MS);

        return () => window.clearTimeout(timer);
    }, [waitsForQr, markReady]);

    const pickup =
        takeover.order_type === 'take_out' && !qrFailed
            ? takeover.pickup
            : null;
    const overall = overallPositionText(takeover.overall_position);

    return (
        <div
            role="status"
            aria-live="assertive"
            className="animate-in fade-in fixed inset-0 z-50 flex flex-col overflow-y-auto bg-[#07130d] pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] text-white duration-300"
        >
            <div aria-hidden="true" className="h-1.5 shrink-0 bg-white/5">
                {showing && (
                    <div
                        className="h-full origin-left bg-emerald-400"
                        style={{
                            animation: `customer-screen-countdown ${showMs}ms linear forwards`,
                        }}
                    />
                )}
            </div>
            <div
                className={`m-auto grid w-full max-w-[1400px] items-center gap-6 px-4 py-6 sm:gap-8 sm:px-8 ${takeover.queue.length > 0 ? 'min-[900px]:grid-cols-[minmax(0,1fr)_minmax(0,1.05fr)]' : ''}`}
            >
                <div className="flex flex-col items-center gap-3 text-center sm:gap-4">
                    <p className="flex items-center gap-2 text-[clamp(14px,2.4vmin,24px)] font-bold text-emerald-200">
                        <CheckCircle2 className="size-[1.2em]" /> Thank you!
                        Your order is placed
                    </p>
                    <p className="text-[clamp(16px,2.8vmin,28px)] font-black tracking-[0.28em] text-white/60 uppercase">
                        Your order
                    </p>
                    <p className="text-[clamp(84px,20vmin,240px)] leading-none font-black tracking-tight text-emerald-400 tabular-nums">
                        {takeover.order_number}
                    </p>
                    <span className="rounded-full border border-emerald-400/40 bg-emerald-400/10 px-5 py-1.5 text-[clamp(15px,2.6vmin,26px)] font-black tracking-[0.18em]">
                        {orderTypeText(takeover.order_type)}
                    </span>
                    {takeover.type_position !== null && (
                        <div className="mt-1 flex flex-col items-center gap-1 rounded-3xl border-2 border-emerald-400 bg-emerald-400/15 px-8 py-3 shadow-[0_0_40px_rgba(52,211,153,0.25)]">
                            <span className="text-[clamp(13px,2.2vmin,22px)] font-black tracking-[0.2em] text-emerald-200">
                                {typeQueueLabel(takeover.order_type)}
                            </span>
                            <span className="text-[clamp(48px,11vmin,120px)] leading-none font-black text-emerald-300 tabular-nums">
                                #{takeover.type_position}
                            </span>
                        </div>
                    )}
                    {overall && (
                        <p className="text-[clamp(15px,2.6vmin,26px)] font-bold text-white/80">
                            {overall}
                        </p>
                    )}
                    {takeover.order_type === 'take_out' && pickup === null && (
                        <p className="max-w-md text-sm text-white/60 sm:text-base">
                            Your receipt has your order number. Please listen
                            for your number.
                        </p>
                    )}
                    {pickup && (
                        <div className="mt-2 flex w-full max-w-[340px] flex-col items-center gap-2 rounded-3xl bg-white p-4 text-center text-neutral-950 shadow-2xl">
                            <img
                                src={pickup.qr_image}
                                alt={`Pickup QR for order ${takeover.order_number}`}
                                onLoad={markReady}
                                onError={() => {
                                    setQrFailed(true);
                                    markReady();
                                }}
                                className="aspect-square w-full max-w-[min(300px,42vmin)] min-w-[200px]"
                            />
                            <p className="flex items-center gap-2 text-base font-black">
                                <QrCode className="size-5" /> Scan to track your
                                order
                            </p>
                            <p className="text-xs text-neutral-600 sm:text-sm">
                                See when it’s ready, view your receipt and get
                                notified.
                            </p>
                        </div>
                    )}
                </div>
                {takeover.queue.length > 0 && (
                    <QueueList
                        rows={takeover.queue}
                        total={takeover.queue_total}
                    />
                )}
            </div>
        </div>
    );
}

function QueueList({
    rows,
    total,
}: {
    rows: CustomerScreenQueueRow[];
    total: number;
}) {
    const first = rows[0]?.position ?? 1;
    const last = rows[rows.length - 1]?.position ?? 1;
    const windowed = first > 1 || last < total;

    return (
        <section
            aria-label="Current queue"
            className="flex min-w-0 flex-col gap-3 rounded-3xl border border-white/10 bg-white/[0.04] p-4 sm:p-5"
        >
            <header className="flex items-baseline justify-between gap-3">
                <h2 className="text-[clamp(14px,2.2vmin,22px)] font-black tracking-[0.2em] text-white/70">
                    CURRENT QUEUE
                </h2>
                {windowed && (
                    <span className="text-xs text-white/50 tabular-nums sm:text-sm">
                        #{first}–#{last} of {total}
                    </span>
                )}
            </header>
            <ol
                className={`grid gap-1.5 sm:gap-2 ${rows.length > 5 ? 'min-[560px]:grid-flow-col min-[560px]:grid-cols-2 min-[560px]:grid-rows-5' : ''}`}
            >
                {rows.map((row) => (
                    <li
                        key={row.position}
                        aria-current={row.current ? 'true' : undefined}
                        className={`grid grid-cols-[2.6em_minmax(0,1fr)_auto] items-center gap-2 rounded-xl px-3 py-2 text-[clamp(14px,2.3vmin,24px)] tabular-nums ${row.current ? 'bg-emerald-400 font-black text-neutral-950 shadow-[0_0_24px_rgba(52,211,153,0.45)]' : 'bg-white/5 font-bold text-white/85'}`}
                    >
                        <span
                            className={
                                row.current
                                    ? 'text-neutral-950/70'
                                    : 'text-white/45'
                            }
                        >
                            {row.position}.
                        </span>
                        <span className="truncate">{row.order_number}</span>
                        <span
                            className={`text-[0.7em] font-black tracking-[0.1em] ${row.current ? 'text-neutral-950' : 'text-white/55'}`}
                        >
                            {orderTypeText(row.order_type)}
                        </span>
                    </li>
                ))}
            </ol>
        </section>
    );
}
