import { CheckCircle2, QrCode } from 'lucide-react';
import {
    useCallback,
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import {
    fitQueueRows,
    fitScale,
    orderTypeText,
    orderTypeTone,
    overallPositionText,
    queueGrid,
    queueRangeText,
    TAKEOVER_READY_TIMEOUT_MS,
    takeoverWaitsForQr,
    typeQueueLabel,
    type CustomerScreenQueueRow,
    type CustomerScreenTakeover as Takeover,
} from '@/lib/customer-screen';

/**
 * The dedicated order confirmation (Phase 19.6) shown above everything after a Dine In or Take Out order is committed.
 * It is its own layout — not the Customer Display board: the customer's large order number and type (Dine In green,
 * Take Out blue, always with its text label), the server-derived positions (the same-type position emphasized over the
 * overall one), a window of the active queue with the customer's own row emphasized, and for Take Out the pickup QR.
 * No customer name is ever part of it.
 *
 * It always fits the screen without scrolling: the order block scales down to its box when it would not fit, and the
 * queue shows only the whole rows that fit (always including the customer's own row).
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
    const tone = orderTypeTone(takeover.order_type);
    const hasQueue = takeover.queue.length > 0;

    return (
        <div
            role="status"
            aria-live="assertive"
            className="animate-in fade-in fixed inset-0 z-50 flex flex-col overflow-hidden bg-[#07130d] pt-[env(safe-area-inset-top)] pr-[env(safe-area-inset-right)] pb-[env(safe-area-inset-bottom)] pl-[env(safe-area-inset-left)] text-white duration-300"
        >
            <div aria-hidden="true" className="h-1.5 shrink-0 bg-white/5">
                {showing && (
                    <div
                        className={`h-full origin-left ${tone.bar}`}
                        style={{
                            animation: `customer-screen-countdown ${showMs}ms linear forwards`,
                        }}
                    />
                )}
            </div>
            <div
                className={`mx-auto grid min-h-0 w-full max-w-400 flex-1 gap-[clamp(8px,2.2vmin,28px)] p-[clamp(8px,2.2vmin,28px)] ${hasQueue ? 'grid-rows-[minmax(0,auto)_minmax(7.5rem,1fr)] landscape:grid-cols-[minmax(0,1fr)_minmax(0,1fr)] landscape:grid-rows-1' : 'grid-rows-1'}`}
            >
                <FitToBox>
                    <div
                        className={`flex flex-col items-center justify-center gap-[clamp(10px,2.4vmin,32px)] text-center ${pickup ? '@min-[34rem]:flex-row' : ''}`}
                    >
                        <div className="flex min-w-0 flex-col items-center gap-[clamp(6px,1.4vmin,16px)]">
                            <p className="flex items-center gap-2 text-[clamp(13px,2.3vmin,24px)] font-bold text-emerald-200">
                                <CheckCircle2 className="size-[1.2em]" /> Thank
                                you! Your order is placed
                            </p>
                            <p className="text-[clamp(13px,2.4vmin,26px)] font-black tracking-[0.28em] text-white/60 uppercase">
                                Your order
                            </p>
                            <p
                                className={`text-[clamp(56px,17vmin,220px)] leading-none font-black tracking-tight tabular-nums ${tone.text}`}
                            >
                                {takeover.order_number}
                            </p>
                            <span
                                className={`rounded-full border-2 px-[1em] py-[0.3em] text-[clamp(13px,2.4vmin,24px)] font-black tracking-[0.18em] ${tone.solid}`}
                            >
                                {orderTypeText(takeover.order_type)}
                            </span>
                            {takeover.type_position !== null && (
                                <div
                                    className={`flex flex-col items-center gap-0.5 rounded-3xl border-2 px-[clamp(16px,3.4vmin,40px)] py-[clamp(4px,1.2vmin,14px)] ${tone.tint}`}
                                >
                                    <span className="text-[clamp(11px,2vmin,20px)] font-black tracking-[0.2em]">
                                        {typeQueueLabel(takeover.order_type)}
                                    </span>
                                    <span className="text-[clamp(34px,9vmin,110px)] leading-none font-black tabular-nums">
                                        #{takeover.type_position}
                                    </span>
                                </div>
                            )}
                            {overall && (
                                <p className="text-[clamp(13px,2.4vmin,24px)] font-bold text-white/80">
                                    {overall}
                                </p>
                            )}
                            {takeover.order_type === 'take_out' &&
                                pickup === null && (
                                    <p className="max-w-md text-[clamp(12px,1.9vmin,18px)] text-white/60">
                                        Your receipt has your order number.
                                        Please listen for your number.
                                    </p>
                                )}
                        </div>
                        {pickup && (
                            <div className="flex shrink-0 flex-col items-center gap-[clamp(4px,1vmin,10px)] rounded-3xl bg-white p-[clamp(8px,1.8vmin,18px)] text-center text-neutral-950 shadow-2xl">
                                <img
                                    src={pickup.qr_image}
                                    alt={`Pickup QR for order ${takeover.order_number}`}
                                    onLoad={markReady}
                                    onError={() => {
                                        setQrFailed(true);
                                        markReady();
                                    }}
                                    className="aspect-square size-[clamp(120px,30vmin,300px)]"
                                />
                                <p className="flex items-center gap-2 text-[clamp(12px,2vmin,18px)] font-black">
                                    <QrCode className="size-[1.2em]" /> Scan to
                                    track your order
                                </p>
                                <p className="max-w-[clamp(120px,30vmin,300px)] text-[clamp(10px,1.5vmin,14px)] text-neutral-600">
                                    See when it’s ready, view your receipt and
                                    get notified.
                                </p>
                            </div>
                        )}
                    </div>
                </FitToBox>
                {hasQueue && (
                    <QueueList
                        rows={takeover.queue}
                        total={takeover.queue_total}
                    />
                )}
            </div>
        </div>
    );
}

/**
 * Centers its content and, when the content is taller or wider than the box, scales it down (a transform, so the
 * layout — and therefore the measurement — never changes). Nothing ever scrolls.
 */
function FitToBox({ children }: { children: ReactNode }) {
    const box = useRef<HTMLDivElement>(null);
    const content = useRef<HTMLDivElement>(null);
    const [scale, setScale] = useState(1);

    useLayoutEffect(() => {
        const outer = box.current;
        const inner = content.current;
        if (outer === null || inner === null) return;
        const measure = () =>
            setScale(
                fitScale(
                    { width: outer.clientWidth, height: outer.clientHeight },
                    { width: inner.scrollWidth, height: inner.offsetHeight },
                ),
            );
        measure();
        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(measure);
        observer.observe(outer);
        observer.observe(inner);

        return () => observer.disconnect();
    }, []);

    return (
        <div
            ref={box}
            className="flex min-h-0 min-w-0 items-center justify-center overflow-hidden"
        >
            <div
                ref={content}
                className="@container w-full shrink-0"
                style={scale < 1 ? { transform: `scale(${scale})` } : undefined}
            >
                {children}
            </div>
        </div>
    );
}

type QueueMeasure = {
    height: number;
    width: number;
    rowHeight: number;
    gap: number;
};

/**
 * The active queue around the customer's order. Rows have one fixed (screen-relative) height, so the list measures
 * how many whole rows fit — in one column, or two when wide enough — and shows only those: the server's own window,
 * trimmed further when needed, always with the customer's row.
 */
function QueueList({
    rows,
    total,
}: {
    rows: CustomerScreenQueueRow[];
    total: number;
}) {
    const list = useRef<HTMLOListElement>(null);
    const [measure, setMeasure] = useState<QueueMeasure | null>(null);

    useLayoutEffect(() => {
        const element = list.current;
        if (element === null) return;
        const read = () => {
            const row = element.firstElementChild as HTMLElement | null;
            const next: QueueMeasure = {
                height: element.clientHeight,
                width: element.clientWidth,
                rowHeight: row?.offsetHeight ?? 0,
                gap: parseFloat(getComputedStyle(element).rowGap) || 0,
            };
            setMeasure((previous) =>
                previous !== null &&
                previous.height === next.height &&
                previous.width === next.width &&
                previous.rowHeight === next.rowHeight &&
                previous.gap === next.gap
                    ? previous
                    : next,
            );
        };
        read();
        if (typeof ResizeObserver === 'undefined') return;
        const observer = new ResizeObserver(read);
        observer.observe(element);

        return () => observer.disconnect();
    }, []);

    const grid =
        measure === null
            ? { rowsPerColumn: rows.length, columns: 1 }
            : queueGrid({ ...measure, minColumnWidth: 240 });
    const visible =
        measure === null
            ? rows
            : fitQueueRows(rows, grid.rowsPerColumn * grid.columns);
    const columns =
        visible.length > grid.rowsPerColumn
            ? Math.min(
                  grid.columns,
                  Math.ceil(visible.length / grid.rowsPerColumn),
              )
            : 1;
    const range = queueRangeText(visible, total);

    return (
        <section
            aria-label="Current queue"
            className="flex min-h-0 min-w-0 flex-col gap-[clamp(6px,1.4vmin,14px)] rounded-3xl border border-white/10 bg-white/4 p-[clamp(8px,2vmin,20px)]"
        >
            <header className="flex shrink-0 items-baseline justify-between gap-3">
                <h2 className="text-[clamp(13px,2.2vmin,22px)] font-black tracking-[0.2em] text-white/70">
                    CURRENT QUEUE
                </h2>
                {range && (
                    <span className="text-[clamp(11px,1.8vmin,16px)] text-white/50 tabular-nums">
                        {range}
                    </span>
                )}
            </header>
            <ol
                ref={list}
                className="grid min-h-0 flex-1 grid-flow-col content-start gap-[clamp(4px,0.9vmin,10px)] overflow-hidden"
                style={{
                    gridTemplateColumns: `repeat(${columns}, minmax(0, 1fr))`,
                    gridTemplateRows: `repeat(${Math.max(1, Math.ceil(visible.length / columns))}, auto)`,
                }}
            >
                {visible.map((row) => (
                    <QueueRow key={row.position} row={row} />
                ))}
            </ol>
        </section>
    );
}

function QueueRow({ row }: { row: CustomerScreenQueueRow }) {
    const tone = orderTypeTone(row.order_type);

    return (
        <li
            aria-current={row.current ? 'true' : undefined}
            className={`grid h-[clamp(34px,5.6vmin,60px)] grid-cols-[2.4em_minmax(0,1fr)_auto] items-center gap-2 rounded-xl border px-3 text-[clamp(14px,2.4vmin,26px)] tabular-nums ${row.current ? `${tone.solid} font-black shadow-[0_0_24px_rgba(255,255,255,0.35)] ring-4 ring-white ring-inset` : `${tone.tint} font-bold`}`}
        >
            <span className="opacity-60">{row.position}.</span>
            <span className="flex min-w-0 items-center gap-2">
                <span className="truncate">{row.order_number}</span>
                {row.current && (
                    <span className="shrink-0 rounded-full bg-neutral-950 px-2 py-0.5 text-[0.6em] tracking-[0.12em] text-white">
                        YOU
                    </span>
                )}
            </span>
            <span className="text-[0.66em] font-black tracking-widest">
                {orderTypeText(row.order_type)}
            </span>
        </li>
    );
}
