import { Link } from '@inertiajs/react';
import { ArrowDown, ArrowUp, Check, Minus } from 'lucide-react';
import { useId, useState } from 'react';
import type { ComponentProps, KeyboardEvent, ReactNode } from 'react';
import { ownerPanelClass } from '@/components/owner-ui';
import { useIsMobile } from '@/hooks/use-mobile';
import {
    CHANNEL_COLORS,
    DELTA_STYLES,
    SERIES_COLORS,
    axisPeso,
    barWidth,
    categoryRowSelected,
    chartGeometry,
    countLabel,
    paymentMixSegments,
    roundedShare,
    shareLabel,
    visibleLabelIndexes,
} from '@/lib/owner-analytics';
import type {
    Analytics,
    CategoryRow,
    Daypart,
    Delta,
    HourBucket,
    PaymentMethodKey,
    PaymentMixData,
    PaymentMixSegment,
    ProductRow,
    TrendBucket,
} from '@/lib/owner-analytics';
import { formatDecimalPeso } from '@/lib/store-close';

export const peso = formatDecimalPeso;

export const ownerLabelClass =
    'text-[10px] font-semibold tracking-[0.07em] text-[#767676] uppercase';

/** A standalone Owner card: 20px radius, restrained border and shadow, 15px bold title with a 12px caption. */
export function AnalyticsCard({
    title,
    hint,
    action,
    children,
    className = '',
}: {
    title: string;
    hint?: ReactNode;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    const id = useId();

    return (
        <section
            aria-labelledby={id}
            className={`${ownerPanelClass} flex min-w-0 flex-col gap-3.5 p-4 sm:p-[18px] ${className}`}
        >
            <div className="flex flex-wrap items-start justify-between gap-2.5">
                <div className="flex min-w-0 flex-col gap-0.5">
                    <h2
                        id={id}
                        className="text-[15px] font-bold tracking-[-0.01em]"
                    >
                        {title}
                    </h2>
                    {hint && (
                        <p className="text-xs leading-5 text-[#767676]">
                            {hint}
                        </p>
                    )}
                </div>
                {action}
            </div>
            {children}
        </section>
    );
}

/** The standalone segmented control (#F2F2F2 track, black active pill). */
export function SegmentedTabs<T extends string>({
    label,
    value,
    options,
    onChange,
    size = 'md',
}: {
    label: string;
    value: T;
    options: readonly (readonly [T, string])[];
    onChange: (value: T) => void;
    size?: 'sm' | 'md' | 'lg';
}) {
    const height =
        size === 'lg'
            ? 'min-h-11 md:min-h-[38px] px-3.5'
            : size === 'md'
              ? 'min-h-11 md:min-h-9 px-[13px]'
              : 'min-h-11 md:min-h-8 px-[11px] text-[11.5px]';

    return (
        <div
            role="group"
            aria-label={label}
            className="owner-hide-scrollbar flex max-w-full shrink-0 items-center gap-[3px] overflow-x-auto rounded-[11px] bg-[#f2f2f2] p-[3px]"
        >
            {options.map(([key, text]) => (
                <button
                    key={key}
                    type="button"
                    aria-pressed={value === key}
                    onClick={() => onChange(key)}
                    className={`inline-flex shrink-0 items-center rounded-[9px] text-[12.5px] font-semibold whitespace-nowrap transition focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${height} ${value === key ? 'bg-[#111] text-white' : 'text-[#666] hover:text-[#111]'}`}
                >
                    {text}
                </button>
            ))}
        </div>
    );
}

export function DeltaPill({ delta }: { delta: Delta | null }) {
    if (delta === null) {
        return null;
    }
    const Icon =
        delta.direction === 'up'
            ? ArrowUp
            : delta.direction === 'down'
              ? ArrowDown
              : Minus;

    return (
        <span
            className={`inline-flex h-[21px] items-center gap-[3px] rounded-full border px-2 text-[11px] font-bold whitespace-nowrap tabular-nums ${DELTA_STYLES[delta.tone]}`}
        >
            <Icon className="size-3" aria-hidden="true" />
            <span>{delta.text}</span>
        </span>
    );
}

export function KpiCard({
    label,
    value,
    delta,
    comparison,
    icon,
    href,
}: {
    label: ReactNode;
    value: string;
    delta: Delta | null;
    comparison: string | null;
    icon?: ReactNode;
    href?: ComponentProps<typeof Link>['href'];
}) {
    const body = (
        <>
            <span className="flex w-full items-center gap-2">
                {icon && (
                    <span
                        aria-hidden="true"
                        className="inline-flex size-[26px] shrink-0 items-center justify-center rounded-lg bg-[#f2f2f2] text-[#111]"
                    >
                        {icon}
                    </span>
                )}
                <span className={`${ownerLabelClass} truncate`}>{label}</span>
            </span>
            <span className="text-[21px] leading-[1.1] font-bold tracking-[-0.025em] [overflow-wrap:anywhere] tabular-nums min-[1250px]:text-[25px] sm:text-[25px]">
                {value}
            </span>
            <span className="flex min-h-[21px] flex-wrap items-center gap-1.5">
                <DeltaPill delta={delta} />
                {comparison && (
                    <span className="text-[10.5px] whitespace-nowrap text-[#8a8a8a]">
                        {comparison}
                    </span>
                )}
            </span>
        </>
    );
    const className = `${ownerPanelClass} flex min-w-0 flex-col items-start gap-2 p-4 text-left`;

    return href ? (
        <Link
            href={href}
            className={`${className} transition hover:border-[#bdbdbd] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none`}
        >
            {body}
        </Link>
    ) : (
        <div className={className}>{body}</div>
    );
}

/** The five standalone KPI cards: Total sales, Transactions, Average order, Items sold and Cashless sales. */
export function KpiGrid({
    analytics,
    comparison,
    icons,
    href,
}: {
    analytics: Analytics;
    comparison: string | null;
    icons?: ReactNode[];
    href?: ComponentProps<typeof Link>['href'];
}) {
    const { kpis } = analytics;
    const cards: [string, string, string, Delta | null][] = [
        [
            'Total sales',
            'Total sales',
            kpis.sales.value === null ? '—' : peso(kpis.sales.value),
            kpis.sales.delta,
        ],
        [
            'Total transactions',
            'Transactions',
            (kpis.transactions.value ?? 0).toLocaleString('en-PH'),
            kpis.transactions.delta,
        ],
        [
            'Average order value',
            'Average order',
            kpis.average_order.value === null
                ? '—'
                : peso(kpis.average_order.value),
            kpis.average_order.delta,
        ],
        [
            'Items sold',
            'Items sold',
            (kpis.items.value ?? 0).toLocaleString('en-PH'),
            kpis.items.delta,
        ],
        [
            'Cashless sales',
            'Cashless sales',
            roundedShare(kpis.cashless_share.value),
            kpis.cashless_share.delta,
        ],
    ];

    return (
        <section
            aria-label="Key figures"
            className="grid grid-cols-2 gap-3 min-[1250px]:grid-cols-5 min-[900px]:grid-cols-3"
        >
            {cards.map(([wide, short, value, delta], index) => (
                <KpiCard
                    key={short}
                    label={
                        wide === short ? (
                            short
                        ) : (
                            <>
                                <span className="min-[1250px]:hidden">
                                    {short}
                                </span>
                                <span className="hidden min-[1250px]:inline">
                                    {wide}
                                </span>
                            </>
                        )
                    }
                    value={value}
                    delta={delta}
                    comparison={comparison}
                    icon={icons?.[index]}
                    href={href}
                />
            ))}
        </section>
    );
}

/**
 * The standalone Sales trend: area + line, dashed previous period, keyboard/hover points with a dark tooltip and
 * collision-free x labels. Money geometry uses exact integer cents; labels show the server's decimal strings.
 */
export function TrendChart({
    buckets,
    metric,
    compare,
    height,
}: {
    buckets: TrendBucket[];
    metric: 'sales' | 'transactions';
    compare: boolean;
    height: number;
}) {
    const mobile = useIsMobile();
    const [hovered, setHovered] = useState<number | null>(null);
    const valueOf = (bucket: {
        sales_cents: number;
        transactions: number;
    }): number =>
        metric === 'sales' ? bucket.sales_cents : bucket.transactions;
    const showPrevious =
        compare && buckets.every((bucket) => bucket.previous !== null);
    const geometry = chartGeometry(
        buckets.map(valueOf),
        showPrevious
            ? buckets.map((bucket) =>
                  bucket.previous === null ? 0 : valueOf(bucket.previous),
              )
            : null,
    );
    const labels = visibleLabelIndexes(buckets.length, mobile);
    const format = (value: number) =>
        metric === 'sales'
            ? axisPeso(value)
            : Math.round(value).toLocaleString('en-PH');
    const step = buckets.length > 1 ? 100 / (buckets.length - 1) : 0;
    const empty = buckets.every((bucket) => valueOf(bucket) === 0);

    return (
        <div className="flex min-w-0 gap-2.5">
            <div
                aria-hidden="true"
                className="flex shrink-0 flex-col justify-between pb-4 text-right text-[9.5px] whitespace-nowrap text-[#949494] tabular-nums"
                style={{ height: height + 16 }}
            >
                {geometry.axis.map((value, index) => (
                    <span key={index}>{format(value)}</span>
                ))}
            </div>
            <div className="flex min-w-0 flex-1 flex-col">
                <div className="relative min-w-0" style={{ height }}>
                    <div
                        aria-hidden="true"
                        className="absolute inset-0 flex flex-col justify-between"
                    >
                        {[0, 1, 2, 3].map((line) => (
                            <div
                                key={line}
                                className={`h-px ${line === 3 ? 'bg-[#e5e5e5]' : 'bg-[#efefef]'}`}
                            />
                        ))}
                    </div>
                    <svg
                        aria-hidden="true"
                        viewBox="0 0 1000 100"
                        preserveAspectRatio="none"
                        className="absolute inset-0 size-full"
                    >
                        {geometry.area && (
                            <path
                                d={geometry.area}
                                fill="rgba(17,17,17,0.07)"
                                stroke="none"
                            />
                        )}
                        {showPrevious && geometry.previousLine && (
                            <path
                                d={geometry.previousLine}
                                fill="none"
                                stroke="#B5B5B5"
                                strokeWidth={1.6}
                                strokeDasharray="5 4"
                                vectorEffect="non-scaling-stroke"
                            />
                        )}
                        {geometry.line && (
                            <path
                                d={geometry.line}
                                fill="none"
                                stroke="#111111"
                                strokeWidth={2.3}
                                strokeLinejoin="round"
                                strokeLinecap="round"
                                vectorEffect="non-scaling-stroke"
                            />
                        )}
                    </svg>
                    {empty && (
                        <p className="absolute inset-x-0 top-1/2 -translate-y-1/2 text-center text-[12px] text-[#8a8a8a]">
                            No sales in this period
                        </p>
                    )}
                    {buckets.map((bucket, index) => {
                        const x = buckets.length > 1 ? index * step : 50;
                        const half = buckets.length > 1 ? step / 2 : 50;
                        const left = Math.max(0, x - half);
                        const right = Math.min(100, x + half);
                        const inner =
                            right - left > 0
                                ? ((x - left) / (right - left)) * 100
                                : 50;
                        const y = 100 - geometry.points[index].y;
                        const hot =
                            hovered === index || buckets.length === 1;
                        const tipPosition =
                            x < 14
                                ? { left: 0 }
                                : x > 86
                                  ? { right: 0 }
                                  : {
                                        left: `${inner}%`,
                                        transform: 'translateX(-50%)',
                                    };
                        const primary =
                            metric === 'sales'
                                ? peso(bucket.sales)
                                : countLabel(bucket.transactions, 'order');
                        const secondary =
                            metric === 'sales'
                                ? countLabel(bucket.transactions, 'order')
                                : `${peso(bucket.sales)} sales`;
                        const previous =
                            showPrevious && bucket.previous
                                ? `Previous: ${metric === 'sales' ? peso(bucket.previous.sales) : countLabel(bucket.previous.transactions, 'order')}`
                                : null;

                        return (
                            <button
                                key={bucket.key}
                                type="button"
                                aria-label={`${bucket.full}: ${primary}, ${secondary}${previous ? `, ${previous}` : ''}`}
                                onMouseEnter={() => setHovered(index)}
                                onMouseLeave={() =>
                                    setHovered((value) =>
                                        value === index ? null : value,
                                    )
                                }
                                onFocus={() => setHovered(index)}
                                onBlur={() =>
                                    setHovered((value) =>
                                        value === index ? null : value,
                                    )
                                }
                                onClick={() => setHovered(index)}
                                className="absolute inset-y-0 z-[2] cursor-pointer border-0 bg-transparent p-0 focus-visible:outline-none"
                                style={{
                                    left: `${left}%`,
                                    width: `${right - left}%`,
                                }}
                            >
                                {hot && (
                                    <span className="pointer-events-none absolute inset-0 block">
                                        {hovered === index && (
                                            <span
                                                className="absolute inset-y-0 w-px bg-[#c9c9c9]"
                                                style={{ left: `${inner}%` }}
                                            />
                                        )}
                                        <span
                                            className="absolute size-[11px] -translate-x-1/2 translate-y-1/2 rounded-full border-[2.5px] border-white bg-[#111] shadow-[0_1px_4px_rgba(0,0,0,0.25)]"
                                            style={{
                                                left: `${inner}%`,
                                                bottom: `${y}%`,
                                            }}
                                        />
                                        {hovered === index && (
                                            <span
                                                className="absolute z-[6] mb-[18px] flex min-w-[124px] flex-col gap-px rounded-[11px] bg-[#111] px-[11px] py-[9px] text-left text-white shadow-[0_12px_28px_rgba(0,0,0,0.24)]"
                                                style={{
                                                    bottom: `${y}%`,
                                                    ...tipPosition,
                                                }}
                                            >
                                                <span className="text-[10px] font-semibold tracking-[0.06em] whitespace-nowrap text-white/60 uppercase">
                                                    {bucket.full}
                                                </span>
                                                <span className="text-[13.5px] font-bold whitespace-nowrap tabular-nums">
                                                    {primary}
                                                </span>
                                                <span className="text-[11px] whitespace-nowrap text-white/70 tabular-nums">
                                                    {secondary}
                                                </span>
                                                {previous && (
                                                    <span className="text-[11px] whitespace-nowrap text-white/55 tabular-nums">
                                                        {previous}
                                                    </span>
                                                )}
                                            </span>
                                        )}
                                    </span>
                                )}
                            </button>
                        );
                    })}
                </div>
                <div
                    aria-hidden="true"
                    className="relative mt-1 h-4 min-w-0"
                >
                    {buckets.map((bucket, index) => {
                        if (!labels.has(index)) {
                            return null;
                        }
                        const x = buckets.length > 1 ? index * step : 50;
                        const position =
                            x < 6
                                ? { left: 0 }
                                : x > 94
                                  ? { right: 0 }
                                  : {
                                        left: `${x}%`,
                                        transform: 'translateX(-50%)',
                                    };

                        return (
                            <span
                                key={bucket.key}
                                className="absolute top-0 text-[9.5px] whitespace-nowrap text-[#949494] tabular-nums"
                                style={position}
                            >
                                {bucket.label}
                            </span>
                        );
                    })}
                </div>
            </div>
        </div>
    );
}

export function CompareToggle({
    on,
    onToggle,
    disabled = false,
}: {
    on: boolean;
    onToggle: () => void;
    disabled?: boolean;
}) {
    return (
        <button
            type="button"
            aria-pressed={on}
            disabled={disabled}
            title={
                disabled
                    ? 'Comparison is not available for a single Store Session'
                    : undefined
            }
            onClick={onToggle}
            className={`inline-flex min-h-11 shrink-0 items-center gap-[7px] rounded-[9px] border px-3 text-xs font-semibold focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-45 md:min-h-[34px] ${on ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white text-[#666]'}`}
        >
            <span
                aria-hidden="true"
                className={`h-0 w-3.5 shrink-0 border-t-2 border-dashed ${on ? 'border-white' : 'border-[#b5b5b5]'}`}
            />
            Compare previous
        </button>
    );
}

export function CompareLegend() {
    return (
        <div className="flex flex-wrap items-center gap-3.5">
            <span className="inline-flex items-center gap-[7px] text-[11.5px] text-[#666]">
                <span className="h-0 w-4 border-t-2 border-[#111]" />
                Current period
            </span>
            <span className="inline-flex items-center gap-[7px] text-[11.5px] text-[#666]">
                <span className="h-0 w-4 border-t-2 border-dashed border-[#b5b5b5]" />
                Previous period
            </span>
        </div>
    );
}

/**
 * Cash and Cashless collections from Payment.amount (net of corrections and voids). Split is explained underneath and
 * is never a third segment, because its legs are already inside Cash and Cashless.
 */
export function PaymentMix({
    collections,
    size = 128,
    bars = false,
}: {
    collections: Analytics['collections'];
    size?: number;
    bars?: boolean;
}) {
    const channels = [
        {
            key: 'cash' as const,
            label: 'Cash',
            amount: collections.cash,
            cents: collections.cash_cents,
            share: collections.cash_share,
            orders: collections.orders.cash,
        },
        {
            key: 'cashless' as const,
            label: 'Cashless',
            amount: collections.cashless,
            cents: collections.cashless_cents,
            share: collections.cashless_share,
            orders: collections.orders.cashless,
        },
    ];
    let offset = 0;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-[18px]">
                <div
                    className="relative shrink-0"
                    style={{ width: size, height: size }}
                >
                    <svg
                        role="img"
                        aria-label={`Cash ${shareLabel(collections.cash_share)}, Cashless ${shareLabel(collections.cashless_share)} of collections`}
                        viewBox="0 0 42 42"
                        className="size-full -rotate-90"
                    >
                        <circle
                            cx="21"
                            cy="21"
                            r="15.9"
                            fill="none"
                            stroke="#F2F2F2"
                            strokeWidth="6"
                        />
                        {channels.map((channel) => {
                            const percent =
                                channel.share === null || channel.cents <= 0
                                    ? 0
                                    : channel.share / 100;
                            const dash = `${percent.toFixed(2)} ${Math.max(0, 100 - percent).toFixed(2)}`;
                            const segment = (
                                <circle
                                    key={channel.key}
                                    cx="21"
                                    cy="21"
                                    r="15.9"
                                    fill="none"
                                    pathLength={100}
                                    stroke={CHANNEL_COLORS[channel.key]}
                                    strokeWidth="6"
                                    strokeDasharray={dash}
                                    strokeDashoffset={(-offset).toFixed(2)}
                                />
                            );
                            offset += percent;

                            return percent > 0 ? segment : null;
                        })}
                    </svg>
                    <div className="absolute inset-0 flex flex-col items-center justify-center gap-px">
                        <span className="text-[9px] font-semibold tracking-[0.08em] text-[#8a8a8a] uppercase">
                            Cashless
                        </span>
                        <span className="text-[20px] font-bold tracking-[-0.02em] tabular-nums">
                            {roundedShare(collections.cashless_share)}
                        </span>
                    </div>
                </div>
                <div className="flex min-w-0 flex-[1_1_150px] flex-col gap-3">
                    {channels.map((channel) => (
                        <div key={channel.key} className="flex flex-col gap-1.5">
                            <div className="flex items-center gap-2.5">
                                <span
                                    aria-hidden="true"
                                    className="size-2.5 shrink-0 rounded-[3px]"
                                    style={{
                                        background: CHANNEL_COLORS[channel.key],
                                    }}
                                />
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-[13px] font-semibold">
                                        {channel.label}
                                    </span>
                                    <span className="text-[11px] text-[#8a8a8a] tabular-nums">
                                        {countLabel(
                                            channel.orders,
                                            `${channel.label.toLowerCase()}-only order`,
                                        )}
                                    </span>
                                </span>
                                <span className="flex shrink-0 flex-col items-end">
                                    <span className="text-[13px] font-semibold whitespace-nowrap tabular-nums">
                                        {peso(channel.amount)}
                                    </span>
                                    <span className="text-[11px] text-[#767676] tabular-nums">
                                        {shareLabel(channel.share)}
                                    </span>
                                </span>
                            </div>
                            {bars && (
                                <div className="h-[7px] overflow-hidden rounded-full bg-[#f2f2f2]">
                                    <div
                                        className="h-full rounded-full"
                                        style={{
                                            width: `${channel.share === null ? 0 : barWidth(channel.share, 10000)}%`,
                                            background:
                                                CHANNEL_COLORS[channel.key],
                                        }}
                                    />
                                </div>
                            )}
                        </div>
                    ))}
                </div>
            </div>
            <p className="rounded-[11px] border border-[#efefef] bg-[#fafafa] px-3 py-2.5 text-[11.5px] leading-[1.5] text-[#666] tabular-nums">
                <span className="font-semibold text-[#111]">
                    Split · {countLabel(collections.split.count, 'payment')}
                </span>{' '}
                {collections.split.count > 0
                    ? `· ${peso(collections.split.total)} (Cash ${peso(collections.split.cash)} + Cashless ${peso(collections.split.cashless)}) — already included above, not added again.`
                    : '— none in this period.'}
            </p>
        </div>
    );
}

/**
 * The Reports Payment method donut, a share of paid sales (₱). By default a Split order's cash and cashless parts are
 * inside Cash and Cashless; with Include split, Split is its own segment and Cash/Cashless are single-method orders.
 * Segments and legend rows are real controls that select a method and show its exact percentage, amount and orders;
 * selecting it again returns to the neutral summary.
 */
export function PaymentMethodDonut({
    mix,
    includeSplit,
    size = 148,
}: {
    mix: PaymentMixData;
    includeSplit: boolean;
    size?: number;
}) {
    const [selected, setSelected] = useState<PaymentMethodKey | null>(null);
    const detailId = useId();
    const { segments, total } = paymentMixSegments(mix, includeSplit);
    const active =
        segments.find(
            (segment) =>
                segment.method === selected && segment.amount_cents > 0,
        ) ?? null;
    const paid = segments.some((segment) => segment.amount_cents > 0);
    const toggle = (method: PaymentMethodKey) =>
        setSelected((current) => (current === method ? null : method));
    const onSegmentKey = (
        event: KeyboardEvent<SVGCircleElement>,
        method: PaymentMethodKey,
    ) => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            toggle(method);
        }
    };
    const ordersText = (segment: PaymentMixSegment) =>
        !includeSplit && segment.split_orders > 0
            ? `${countLabel(segment.orders, 'order')} · ${segment.split_orders} split`
            : countLabel(segment.orders, 'order');

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-[18px]">
                <div
                    className="relative shrink-0"
                    style={{ width: size, height: size }}
                >
                    <svg
                        role="group"
                        aria-label={`Payment method: share of ${peso(total)} paid sales`}
                        viewBox="0 0 42 42"
                        className="size-full -rotate-90"
                    >
                        <circle
                            cx="21"
                            cy="21"
                            r="15.9"
                            fill="none"
                            stroke="#F2F2F2"
                            strokeWidth="6"
                        />
                        {segments.map((segment) =>
                            segment.share === null ||
                            segment.amount_cents <= 0 ? null : (
                                <circle
                                    key={segment.method}
                                    role="button"
                                    tabIndex={0}
                                    aria-pressed={active?.method === segment.method}
                                    aria-describedby={detailId}
                                    aria-label={`${segment.label}: ${shareLabel(segment.share)}, ${peso(segment.amount)}, ${ordersText(segment)}`}
                                    onClick={() => toggle(segment.method)}
                                    onKeyDown={(event) =>
                                        onSegmentKey(event, segment.method)
                                    }
                                    cx="21"
                                    cy="21"
                                    r="15.9"
                                    fill="none"
                                    pathLength={100}
                                    stroke={segment.color}
                                    strokeWidth={
                                        active?.method === segment.method
                                            ? 7.5
                                            : 6
                                    }
                                    strokeDasharray={segment.dash}
                                    strokeDashoffset={segment.offset}
                                    className={`cursor-pointer transition-[opacity,stroke-width] outline-none focus-visible:stroke-8 ${active && active.method !== segment.method ? 'opacity-35' : ''}`}
                                />
                            ),
                        )}
                    </svg>
                    <div
                        aria-hidden="true"
                        className="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-px px-6 text-center"
                    >
                        <span className="text-[9px] font-semibold tracking-[0.08em] text-[#8a8a8a] uppercase">
                            {active ? active.label : 'Paid'}
                        </span>
                        <span
                            className={`leading-tight font-bold tracking-[-0.02em] tabular-nums ${active ? 'text-[20px]' : 'text-[14px]'}`}
                        >
                            {active ? shareLabel(active.share) : peso(total)}
                        </span>
                        <span className="text-[10px] leading-tight text-[#767676] tabular-nums">
                            {active
                                ? peso(active.amount)
                                : countLabel(mix.paid_orders, 'order')}
                        </span>
                    </div>
                </div>
                <ul className="flex min-w-0 flex-[1_1_170px] flex-col gap-1.5">
                    {segments.map((segment) => {
                        const on = active?.method === segment.method;

                        return (
                            <li key={segment.method}>
                                <button
                                    type="button"
                                    aria-pressed={on}
                                    aria-describedby={detailId}
                                    disabled={segment.amount_cents <= 0}
                                    onClick={() => toggle(segment.method)}
                                    className={`flex min-h-11 w-full flex-col gap-1.5 rounded-xl border px-2.5 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none disabled:cursor-default ${on ? 'border-[#111] bg-[#fafafa]' : 'border-transparent enabled:hover:bg-[#fafafa]'} ${active && !on ? 'opacity-55' : ''}`}
                                >
                                    <span className="flex w-full items-center gap-[9px]">
                                        <span
                                            aria-hidden="true"
                                            className="size-2.5 shrink-0 rounded-[3px]"
                                            style={{ background: segment.color }}
                                        />
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="text-[13px] font-semibold">
                                                {segment.label}
                                            </span>
                                            <span className="text-[11px] text-[#8a8a8a] tabular-nums">
                                                {ordersText(segment)}
                                            </span>
                                        </span>
                                        <span className="flex shrink-0 flex-col items-end">
                                            <span className="text-[13px] font-semibold whitespace-nowrap tabular-nums">
                                                {peso(segment.amount)}
                                            </span>
                                            <span className="text-[11px] text-[#767676] tabular-nums">
                                                {shareLabel(segment.share)}
                                            </span>
                                        </span>
                                    </span>
                                    <span
                                        aria-hidden="true"
                                        className="block h-[7px] w-full overflow-hidden rounded-full bg-[#f2f2f2]"
                                    >
                                        <span
                                            className="block h-full rounded-full"
                                            style={{
                                                width: `${segment.share === null ? 0 : barWidth(segment.share, 10000)}%`,
                                                background: segment.color,
                                            }}
                                        />
                                    </span>
                                </button>
                            </li>
                        );
                    })}
                </ul>
            </div>
            <p
                id={detailId}
                aria-live="polite"
                className="rounded-[11px] border border-[#efefef] bg-[#fafafa] px-3 py-2.5 text-[11.5px] leading-[1.5] text-[#666] tabular-nums"
            >
                {active ? (
                    <>
                        <span className="font-semibold text-[#111]">
                            {active.label} · {shareLabel(active.share)}
                        </span>{' '}
                        of {peso(total)} paid · {peso(active.amount)} ·{' '}
                        {ordersText(active)}
                    </>
                ) : paid ? (
                    `${peso(total)} paid across ${countLabel(mix.paid_orders, 'order')} · tap a method or its segment for the exact amount.`
                ) : (
                    'No paid sales in this period.'
                )}
            </p>
            {mix.split_orders > 0 && (
                <p className="text-[11.5px] leading-[1.5] text-[#666] tabular-nums">
                    {includeSplit
                        ? `Split = ${countLabel(mix.split_orders, 'order')} paid with both Cash and Cashless, shown as one amount.`
                        : `${countLabel(mix.split_orders, 'split order')} ${mix.split_orders === 1 ? 'is' : 'are'} counted by ${mix.split_orders === 1 ? 'its' : 'their'} cash and cashless parts · check Include split to show Split separately.`}
                </p>
            )}
            {!isZeroAmount(mix.split_pending) && (
                <p className="text-[11.5px] leading-[1.5] text-[#8a4b00] tabular-nums">
                    {peso(mix.split_pending)} of corrections on split orders is
                    pending allocation and not deducted from Cash or Cashless.
                </p>
            )}
            {mix.unpaid.transactions > 0 && (
                <p className="text-[11.5px] leading-[1.5] text-[#666] tabular-nums">
                    {countLabel(mix.unpaid.transactions, 'unpaid Pay Later order')}{' '}
                    ({peso(mix.unpaid.sales)}) not in this chart until settled.
                </p>
            )}
            <p className="text-[11px] leading-[1.5] text-[#8a8a8a]">
                Percentages are shares of paid sales (₱).
            </p>
        </div>
    );
}

/** True for a zero decimal money string such as "0.00". */
function isZeroAmount(amount: string): boolean {
    return /^-?0+(\.0+)?$/.test(amount);
}

/**
 * Sales by category. With `onPick`, each row is a real toggle button for the category filter values it stands for;
 * `selected` holds the active category filter.
 */
export function CategoryBars({
    categories,
    selected = [],
    onPick,
}: {
    categories: CategoryRow[];
    selected?: readonly string[];
    onPick?: (category: CategoryRow) => void;
}) {
    if (categories.length === 0) {
        return <EmptyNote>No items sold in this period.</EmptyNote>;
    }
    const max = Math.max(...categories.map((category) => category.sales_cents));
    const filtering = selected.length > 0;

    return (
        <ul className="flex flex-col gap-[9px]">
            {categories.map((category, index) => {
                const color = SERIES_COLORS[index % SERIES_COLORS.length];
                const on = categoryRowSelected(category, selected);
                const content = (
                    <>
                        <span className="flex w-full items-center gap-[9px]">
                            <span
                                aria-hidden="true"
                                className="size-2.5 shrink-0 rounded-[3px]"
                                style={{ background: color }}
                            />
                            <span className="flex min-w-0 flex-1 flex-col text-left">
                                <span className="flex min-w-0 items-center gap-1.5">
                                    <span className="truncate text-[13px] font-semibold">
                                        {category.name}
                                    </span>
                                    {onPick && on && (
                                        <Check
                                            className="size-3.5 shrink-0"
                                            aria-hidden="true"
                                        />
                                    )}
                                </span>
                                <span className="text-[11px] text-[#8a8a8a] tabular-nums">
                                    {countLabel(category.items, 'item')} sold
                                </span>
                            </span>
                            <span className="text-[13px] font-semibold whitespace-nowrap tabular-nums">
                                {peso(category.sales)}
                            </span>
                            <span className="w-[46px] text-right text-[11.5px] text-[#767676] tabular-nums">
                                {shareLabel(category.share)}
                            </span>
                        </span>
                        <span className="block h-2 w-full overflow-hidden rounded-full bg-[#f2f2f2]">
                            <span
                                className="block h-full rounded-full"
                                style={{
                                    width: `${barWidth(category.sales_cents, max)}%`,
                                    background: color,
                                }}
                            />
                        </span>
                    </>
                );

                return (
                    <li key={category.key}>
                        {onPick ? (
                            <button
                                type="button"
                                aria-pressed={on}
                                onClick={() => onPick(category)}
                                className={`flex min-h-11 w-full flex-col gap-[7px] rounded-xl border px-[11px] py-2.5 text-left transition focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${on ? 'border-[#111] bg-[#fafafa]' : `border-[#efefef] bg-white hover:border-[#c9c9c9] ${filtering ? 'opacity-55 hover:opacity-100' : ''}`}`}
                            >
                                {content}
                            </button>
                        ) : (
                            <div className="flex flex-col gap-[7px]">
                                {content}
                            </div>
                        )}
                    </li>
                );
            })}
        </ul>
    );
}

/** Vertical bars for clock hours or two-hour dayparts; the tallest bar is black and every bar has a text title. */
export function HourBars({
    bars,
    metric,
    height,
    labelEvery = 1,
}: {
    bars: (HourBucket | Daypart)[];
    metric: 'sales' | 'transactions';
    height: number;
    labelEvery?: number;
}) {
    const values = bars.map((bar) =>
        metric === 'sales' ? bar.sales_cents : bar.transactions,
    );
    const max = Math.max(0, ...values);

    return (
        <ul
            className="flex items-end gap-1 sm:gap-[7px]"
            style={{ height }}
            aria-label={`${metric === 'sales' ? 'Sales' : 'Transactions'} by hour`}
        >
            {bars.map((bar, index) => {
                const value = values[index];
                const full = bar.full;
                const title = `${full} · ${peso(bar.sales)} · ${countLabel(bar.transactions, 'order')}`;

                return (
                    <li
                        key={bar.hour}
                        className="flex h-full min-w-0 flex-1 flex-col items-center justify-end gap-1.5"
                    >
                        <span className="sr-only">{title}</span>
                        <div
                            aria-hidden="true"
                            title={title}
                            className="w-full rounded-t-[5px]"
                            style={{
                                height: `${max > 0 ? Math.max(3, Math.round((value / max) * 100)) : 3}%`,
                                minHeight: 3,
                                background:
                                    max > 0 && value === max
                                        ? '#111111'
                                        : '#D8D8D8',
                            }}
                        />
                        <span
                            aria-hidden="true"
                            className="h-[11px] text-[9px] whitespace-nowrap text-[#949494]"
                        >
                            {index % labelEvery === 0 ? bar.label : ''}
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

export function TopProductBars({
    products,
    metric,
    limit,
}: {
    products: ProductRow[];
    metric: 'sales' | 'quantity';
    limit: number;
}) {
    const rows = [...products]
        .sort((a, b) =>
            metric === 'quantity'
                ? b.quantity - a.quantity || b.sales_cents - a.sales_cents
                : b.sales_cents - a.sales_cents || b.quantity - a.quantity,
        )
        .slice(0, limit);
    if (rows.length === 0) {
        return <EmptyNote>No products sold in this period.</EmptyNote>;
    }
    const top =
        metric === 'quantity' ? rows[0].quantity : rows[0].sales_cents;

    return (
        <ol className="flex flex-col gap-[11px]">
            {rows.map((product, index) => {
                const value =
                    metric === 'quantity'
                        ? product.quantity
                        : product.sales_cents;

                return (
                    <li key={product.key} className="flex items-center gap-[11px]">
                        <span
                            aria-hidden="true"
                            className="inline-flex size-6 shrink-0 items-center justify-center rounded-[7px] bg-[#f2f2f2] text-[11px] font-bold tabular-nums"
                        >
                            {index + 1}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-[5px]">
                            <span className="flex min-w-0 items-baseline gap-2">
                                <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                                    <span className="sr-only">
                                        Rank {index + 1}:{' '}
                                    </span>
                                    {product.name}
                                </span>
                                <span className="shrink-0 text-[11px] text-[#8a8a8a] tabular-nums">
                                    {metric === 'quantity'
                                        ? peso(product.sales)
                                        : `${product.quantity.toLocaleString('en-PH')} sold`}
                                </span>
                            </span>
                            <span
                                aria-hidden="true"
                                className="block h-[7px] rounded-full"
                                style={{
                                    width: `${barWidth(value, top, 2)}%`,
                                    background:
                                        index === 0 ? '#111111' : '#C9C9C9',
                                }}
                            />
                        </span>
                        <span className="w-[92px] shrink-0 text-right text-[13px] font-bold whitespace-nowrap tabular-nums">
                            {metric === 'quantity'
                                ? `${product.quantity.toLocaleString('en-PH')} sold`
                                : peso(product.sales)}
                        </span>
                    </li>
                );
            })}
        </ol>
    );
}

export function EmptyNote({ children }: { children: ReactNode }) {
    return (
        <p className="rounded-xl border border-dashed border-[#e0e0e0] px-4 py-7 text-center text-[12.5px] text-[#767676]">
            {children}
        </p>
    );
}

export function MiniStat({
    label,
    value,
    note,
    delta,
}: {
    label: string;
    value: ReactNode;
    note?: ReactNode;
    delta?: Delta | null;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-1 rounded-xl border border-[#efefef] bg-[#fafafa] px-3 py-[13px]">
            <span className={ownerLabelClass}>{label}</span>
            <span className="text-[21px] leading-[1.05] font-bold tracking-[-0.02em] [overflow-wrap:anywhere] tabular-nums">
                {value}
            </span>
            {delta && (
                <span>
                    <DeltaPill delta={delta} />
                </span>
            )}
            {note && (
                <span className="text-[10.5px] leading-[1.4] text-[#8a8a8a]">
                    {note}
                </span>
            )}
        </div>
    );
}

/** Neutral per-Branch facts for All Branches; rows are alphabetical and never ranked. */
export function BranchComparison({
    branches,
}: {
    branches: NonNullable<Analytics['branches']>;
}) {
    const max = Math.max(0, ...branches.map((row) => row.sales_cents));
    const expensesHidden = branches.some((row) => row.expenses === null);

    return (
        <div className="flex flex-col gap-2.5">
            <div className="hidden overflow-x-auto rounded-[13px] border border-[#efefef] md:block">
                <table className="w-full min-w-[640px] text-left text-[13px] tabular-nums">
                    <caption className="sr-only">
                        Branch comparison, alphabetical
                    </caption>
                    <thead className="bg-[#fafafa]">
                        <tr className={ownerLabelClass}>
                            <th scope="col" className="px-3.5 py-2.5 font-semibold">
                                Branch
                            </th>
                            <th scope="col" className="px-3.5 py-2.5 text-right font-semibold">
                                Sessions
                            </th>
                            <th scope="col" className="px-3.5 py-2.5 text-right font-semibold">
                                Orders
                            </th>
                            <th scope="col" className="px-3.5 py-2.5 text-right font-semibold">
                                Sales
                            </th>
                            <th scope="col" className="px-3.5 py-2.5 text-right font-semibold">
                                Cash
                            </th>
                            <th scope="col" className="px-3.5 py-2.5 text-right font-semibold">
                                Cashless
                            </th>
                            <th scope="col" className="px-3.5 py-2.5 text-right font-semibold">
                                Expenses
                            </th>
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[#f3f3f3]">
                        {branches.map((row) => (
                            <tr key={row.branch.id}>
                                <th scope="row" className="px-3.5 py-2.5 text-left font-normal">
                                    <span className="block max-w-[240px] truncate font-semibold">
                                        {row.branch.name}
                                    </span>
                                    <span className="block text-[11px] text-[#767676]">
                                        {row.branch.code}
                                        {row.branch.status !== 'active' &&
                                            ` · ${row.branch.status.replace('_', ' ')}`}
                                    </span>
                                </th>
                                <td className="px-3.5 py-2.5 text-right">{row.sessions}</td>
                                <td className="px-3.5 py-2.5 text-right">{row.orders}</td>
                                <td className="px-3.5 py-2.5 text-right">
                                    <span className="block font-bold">{peso(row.sales)}</span>
                                    <span
                                        aria-hidden="true"
                                        className="mt-1 ml-auto block h-1 rounded-full bg-[#111]"
                                        style={{ width: `${barWidth(row.sales_cents, max, 2)}%` }}
                                    />
                                </td>
                                <td className="px-3.5 py-2.5 text-right">{peso(row.cash)}</td>
                                <td className="px-3.5 py-2.5 text-right">{peso(row.cashless)}</td>
                                <td className="px-3.5 py-2.5 text-right">
                                    {row.expenses === null ? '—' : peso(row.expenses)}
                                </td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
            <ul className="grid gap-2 md:hidden">
                {branches.map((row) => (
                    <li
                        key={row.branch.id}
                        className="flex flex-col gap-2 rounded-xl border border-[#efefef] bg-[#fafafa] p-3"
                    >
                        <div className="flex items-baseline justify-between gap-3">
                            <span className="min-w-0 truncate text-[13.5px] font-bold">
                                {row.branch.code} · {row.branch.name}
                            </span>
                            <span className="shrink-0 text-[14px] font-bold tabular-nums">
                                {peso(row.sales)}
                            </span>
                        </div>
                        <dl className="grid grid-cols-2 gap-x-3 gap-y-1.5 text-[12px] tabular-nums">
                            {(
                                [
                                    ['Sessions', String(row.sessions)],
                                    ['Orders', String(row.orders)],
                                    ['Cash', peso(row.cash)],
                                    ['Cashless', peso(row.cashless)],
                                    ['Expenses', row.expenses === null ? '—' : peso(row.expenses)],
                                ] as const
                            ).map(([label, value]) => (
                                <div key={label} className="min-w-0">
                                    <dt className="text-[10px] tracking-[0.06em] text-[#8a8a8a] uppercase">
                                        {label}
                                    </dt>
                                    <dd className="font-semibold [overflow-wrap:anywhere]">{value}</dd>
                                </div>
                            ))}
                        </dl>
                    </li>
                ))}
            </ul>
            {expensesHidden && (
                <p className="text-[11.5px] text-[#767676]">
                    Expenses are drawer-level and are not tied to an order, so
                    they are hidden while an order filter is active.
                </p>
            )}
        </div>
    );
}
