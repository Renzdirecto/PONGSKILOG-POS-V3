/**
 * Types and presentation helpers for the Owner Dashboard and Reports analytics.
 *
 * Every figure is computed by the server (SalesAnalytics). Money arrives as exact decimal strings for display and as
 * integer cents only where a chart needs geometry; nothing here adds, nets or re-derives an accounting total.
 */

export type Delta = {
    direction: 'up' | 'down' | 'flat';
    text: string;
    tone: 'good' | 'bad' | 'neutral';
};

export type Kpi<T> = { value: T | null; previous: T | null; delta: Delta | null };

export type TrendBucket = {
    key: string;
    label: string;
    full: string;
    sales: string;
    sales_cents: number;
    transactions: number;
    previous: {
        sales: string;
        sales_cents: number;
        transactions: number;
    } | null;
};

export type HourBucket = {
    hour: number;
    label: string;
    long: string;
    full: string;
    sales: string;
    sales_cents: number;
    transactions: number;
};

export type Daypart = {
    hour: number;
    label: string;
    full: string;
    sales: string;
    sales_cents: number;
    transactions: number;
};

export type CategoryRow = {
    /** A category UUID, "uncategorized", or "others" for the grouped tail. */
    key: string;
    /** The category filter values this row stands for. */
    ids: string[];
    name: string;
    sales: string;
    sales_cents: number;
    items: number;
    share: number | null;
};

export type ProductRow = {
    key: string;
    name: string;
    category: string;
    quantity: number;
    orders: number;
    sales: string;
    sales_cents: number;
    share: number | null;
    average_price: string | null;
};

export type CashierRow = {
    id: number | null;
    name: string;
    transactions: number;
    sales: string;
    sales_cents: number;
    average: string | null;
    cash: string;
    cashless: string;
    split: string;
    unpaid: string;
};

export type OrderTypeRow = {
    type: 'dine_in' | 'take_out';
    label: string;
    sales: string;
    sales_cents: number;
    transactions: number;
    items: number;
    average: string | null;
    share: number | null;
};

export type BranchComparisonRow = {
    branch: { id: string; name: string; code: string; status: string };
    sessions: number;
    orders: number;
    sales: string;
    sales_cents: number;
    share: number | null;
    cash: string;
    cashless: string;
    expenses: string | null;
};

export type Highlight = {
    label: string;
    value: string;
    amount: string | null;
    detail: string;
};

export type FilterOption<T extends string | number> = { value: T; label: string };

export type PaymentMethodKey = 'cash' | 'cashless' | 'split';

export type PaymentMixMethod = {
    method: PaymentMethodKey;
    label: string;
    transactions: number;
    sales: string;
    sales_cents: number;
    /** Share of Cash + Cashless transactions in basis points; always null for Split. */
    share: number | null;
    /** Share of all paid transactions in basis points. */
    share_with_split: number | null;
};

export type PaymentMixData = {
    basis: 'transactions';
    methods: PaymentMixMethod[];
    unpaid: { transactions: number; sales: string };
};

export type Analytics = {
    comparison: { available: boolean; description: string; label: string };
    filters: {
        order_types: string[];
        payment_methods: string[];
        cashiers: number[];
        /** Narrows product rows only (Top products, Product performance). */
        categories: string[];
        /** True when an order filter narrows the Orders (never set by the category filter). */
        active: boolean;
    };
    filter_options: {
        order_types: FilterOption<string>[];
        payment_methods: FilterOption<string>[];
        cashiers: FilterOption<number>[];
        categories: FilterOption<string>[];
    };
    kpis: {
        sales: Kpi<string>;
        transactions: Kpi<number>;
        average_order: Kpi<string>;
        items: Kpi<number>;
        cashless_share: Kpi<number>;
    };
    trend: {
        granularity: 'hour' | 'day' | 'month';
        buckets: TrendBucket[];
        peak: { sales: string; transactions: number };
    };
    collections: {
        cash: string;
        cashless: string;
        total: string;
        cash_cents: number;
        cashless_cents: number;
        cash_share: number | null;
        cashless_share: number | null;
        orders: { cash: number; cashless: number; split: number; unpaid: number };
        split: { count: number; total: string; cash: string; cashless: string };
        unallocated: string;
    };
    payment_mix: PaymentMixData;
    categories: CategoryRow[];
    order_types: OrderTypeRow[];
    hours: HourBucket[];
    dayparts: Daypart[];
    peak_hour: {
        hour: number;
        label: string;
        sales: string;
        sales_cents: number;
        transactions: number;
    } | null;
    products: ProductRow[];
    cashiers: CashierRow[];
    kitchen: {
        completed: number;
        completed_delta: Delta | null;
        average_prep_seconds: number | null;
        prep_delta: Delta | null;
        timed_orders: number;
        by_hour: (Omit<HourBucket, 'sales' | 'sales_cents' | 'transactions'> & {
            average_seconds: number | null;
            orders: number;
        })[];
        fastest: { full: string; average_seconds: number } | null;
        slowest: { full: string; average_seconds: number } | null;
    };
    highlights: Highlight[];
    branches: BranchComparisonRow[] | null;
};

export type KitchenNow = {
    open_sessions: number;
    kitchen: number;
    preparing: number;
    ready: number;
    oldest: {
        order_number: string;
        branch_code: string;
        status: string;
        waiting_seconds: number;
        since: string;
    } | null;
    as_of: string;
};

/** Category and series colours from the Owner standalone, in order; later entries extend the same restrained palette. */
export const SERIES_COLORS = [
    '#111111',
    '#1D4ED8',
    '#15803D',
    '#B45309',
    '#8A8A8A',
    '#7C3AED',
    '#0E7490',
];

export const CHANNEL_COLORS = { cash: '#111111', cashless: '#1D4ED8' };

/** Payment method colours from the Owner standalone; Split takes its restrained grey. */
export const METHOD_COLORS: Record<PaymentMethodKey, string> = {
    ...CHANNEL_COLORS,
    split: '#8A8A8A',
};

export type PaymentMixSegment = PaymentMixMethod & {
    /** The server share for the visible whole, in basis points (null when that whole is empty). */
    visibleShare: number | null;
    color: string;
    /** SVG dash geometry on a pathLength of 100. */
    dash: string;
    offset: string;
};

/**
 * The Payment method donut: Cash and Cashless by default, plus Split when it is included. Every method is a
 * mutually exclusive order class, so Split is added as its own segment and never on top of Cash or Cashless. The
 * shares come from the server (exactly 100.0% of the visible transactions); this only lays them out.
 */
export function paymentMixSegments(
    mix: PaymentMixData,
    includeSplit: boolean,
): {
    segments: PaymentMixSegment[];
    paidTransactions: number;
    excludedSplit: number;
} {
    let offset = 0;
    const segments = mix.methods
        .filter((method) => includeSplit || method.method !== 'split')
        .map((method) => {
            const visibleShare = includeSplit
                ? method.share_with_split
                : method.share;
            const percent =
                visibleShare === null || method.transactions === 0
                    ? 0
                    : visibleShare / 100;
            const segment = {
                ...method,
                visibleShare,
                color: METHOD_COLORS[method.method],
                dash: `${percent.toFixed(2)} ${Math.max(0, 100 - percent).toFixed(2)}`,
                offset: (-offset).toFixed(2),
            };
            offset += percent;

            return segment;
        });

    return {
        segments,
        paidTransactions: segments.reduce(
            (total, segment) => total + segment.transactions,
            0,
        ),
        excludedSplit: includeSplit
            ? 0
            : (mix.methods.find((method) => method.method === 'split')
                  ?.transactions ?? 0),
    };
}

/** A Sales by category row is selected when the category filter holds every category it stands for. */
export function categoryRowSelected(
    row: Pick<CategoryRow, 'ids'>,
    selected: readonly string[],
): boolean {
    return (
        row.ids.length > 0 && row.ids.every((id) => selected.includes(id))
    );
}

/** Picking a row filters to exactly its categories; picking the row that is already the whole filter clears it. */
export function nextCategorySelection(
    row: Pick<CategoryRow, 'ids'>,
    selected: readonly string[],
): string[] {
    const same =
        selected.length === row.ids.length &&
        row.ids.every((id) => selected.includes(id));

    return same ? [] : [...row.ids];
}

/** A server share in basis points as a percentage label, or an em dash when there is no whole to share. */
export function shareLabel(basisPoints: number | null, digits = 1): string {
    if (basisPoints === null) {
        return '—';
    }

    return `${(basisPoints / 100).toFixed(digits)}%`;
}

/** Rounded whole-percent label used by the standalone donut centre and KPI card. */
export function roundedShare(basisPoints: number | null): string {
    return basisPoints === null ? '—' : `${Math.round(basisPoints / 100)}%`;
}

/** A proportional bar width in percent with the standalone minimum so a non-zero value stays visible. */
export function barWidth(value: number, max: number, minimum = 1.5): number {
    if (max <= 0 || value <= 0) {
        return 0;
    }

    return Math.max(minimum, Math.min(100, (value / max) * 100));
}

export function durationLabel(seconds: number | null): string {
    if (seconds === null) {
        return '—';
    }
    const whole = Math.max(0, Math.round(seconds));

    return `${Math.floor(whole / 60)}m ${String(whole % 60).padStart(2, '0')}s`;
}

export function waitingLabel(seconds: number): string {
    const whole = Math.max(0, Math.round(seconds));
    const hours = Math.floor(whole / 3600);
    const minutes = Math.floor((whole % 3600) / 60);
    const rest = whole % 60;

    return hours > 0
        ? `${hours}:${String(minutes).padStart(2, '0')}:${String(rest).padStart(2, '0')}`
        : `${minutes}:${String(rest).padStart(2, '0')}`;
}

export function countLabel(count: number, singular: string, plural?: string): string {
    return `${count.toLocaleString('en-PH')} ${count === 1 ? singular : (plural ?? `${singular}s`)}`;
}

/** Compact whole-peso axis labels (₱0, ₱1.2k, ₱12k, ₱1.2M) for chart axes only; cards always show exact money. */
export function axisPeso(cents: number): string {
    const pesos = cents / 100;
    if (pesos >= 1_000_000) {
        return `₱${(pesos / 1_000_000).toFixed(pesos >= 10_000_000 ? 0 : 1)}M`;
    }
    if (pesos >= 1_000) {
        return `₱${(pesos / 1_000).toFixed(pesos >= 10_000 ? 0 : 1)}k`;
    }

    return `₱${Math.round(pesos)}`;
}

export type ChartPoint = { x: number; y: number };

/**
 * SVG geometry for a 1000×100 viewBox line chart. A single point sits in the middle; values are never negative in the
 * sales trend, but a negative is clamped to the baseline rather than drawn below the axis.
 */
export function chartGeometry(
    values: number[],
    previous: number[] | null,
): {
    max: number;
    line: string;
    area: string;
    previousLine: string;
    points: ChartPoint[];
    axis: number[];
} {
    const peak = Math.max(0, ...values, ...(previous ?? []));
    const max = peak > 0 ? peak * 1.1 : 1;
    const pointsOf = (series: number[]): ChartPoint[] =>
        series.map((value, index) => ({
            x: series.length > 1 ? (index / (series.length - 1)) * 1000 : 500,
            y: 100 - (Math.max(0, value) / max) * 100,
        }));
    const pathOf = (points: ChartPoint[]): string =>
        points
            .map(
                (point, index) =>
                    `${index === 0 ? 'M' : 'L'}${point.x.toFixed(2)} ${point.y.toFixed(2)}`,
            )
            .join(' ');
    const points = pointsOf(values);
    const line = values.length > 1 ? pathOf(points) : '';

    return {
        max,
        line,
        area: line === '' ? '' : `${line} L1000 100 L0 100 Z`,
        previousLine:
            previous !== null && previous.length > 1
                ? pathOf(pointsOf(previous))
                : '',
        points,
        axis: [max, max / 2, 0],
    };
}

/** Which x-axis labels to print so they never collide: every nth label plus the last one. */
export function visibleLabelIndexes(count: number, mobile: boolean): Set<number> {
    const every = Math.max(1, Math.ceil(count / (mobile ? 5 : 13)));
    const indexes = new Set<number>();
    for (let index = 0; index < count; index += every) {
        indexes.add(index);
    }
    if (count > 0) {
        indexes.add(count - 1);
    }

    return indexes;
}

export type ProductSort = 'sales_desc' | 'sales_asc' | 'qty_desc' | 'qty_asc';

export const PRODUCT_SORTS: [ProductSort, string][] = [
    ['sales_desc', 'Highest sales'],
    ['sales_asc', 'Lowest sales'],
    ['qty_desc', 'Most sold'],
    ['qty_asc', 'Least sold'],
];

/** Orders the bounded product list for the Product performance table; ties keep the server's ranking. */
export function sortProducts(
    products: ProductRow[],
    sort: ProductSort,
    categories: string[] | null,
): ProductRow[] {
    const rows = products
        .map((product, index) => ({ product, index }))
        .filter(
            ({ product }) =>
                categories === null || categories.includes(product.category),
        );
    const compare = {
        sales_desc: (a: ProductRow, b: ProductRow) => b.sales_cents - a.sales_cents,
        sales_asc: (a: ProductRow, b: ProductRow) => a.sales_cents - b.sales_cents,
        qty_desc: (a: ProductRow, b: ProductRow) => b.quantity - a.quantity,
        qty_asc: (a: ProductRow, b: ProductRow) => a.quantity - b.quantity,
    }[sort];

    return rows
        .sort((a, b) => compare(a.product, b.product) || a.index - b.index)
        .map(({ product }) => product);
}

export const DELTA_STYLES: Record<Delta['tone'], string> = {
    good: 'border-[#BBF7D0] bg-[#F0FDF4] text-[#15803D]',
    bad: 'border-[#EFC5C5] bg-[#FEF2F2] text-[#B91C1C]',
    neutral: 'border-[#E5E5E5] bg-[#F2F2F2] text-[#666666]',
};

export const KITCHEN_CHIP: Record<string, { label: string; className: string }> = {
    kitchen: {
        label: 'IN KITCHEN',
        className: 'border-[#BFDBFE] bg-[#EFF6FF] text-[#1D4ED8]',
    },
    preparing: {
        label: 'PREPARING',
        className: 'border-[#BFDBFE] bg-[#EFF6FF] text-[#1D4ED8]',
    },
    ready: {
        label: 'READY',
        className: 'border-[#BBF7D0] bg-[#F0FDF4] text-[#15803D]',
    },
    done: {
        label: 'DONE',
        className: 'border-[#BBF7D0] bg-[#F0FDF4] text-[#15803D]',
    },
};

export const PAYMENT_METHOD_LABELS: Record<string, string> = {
    cash: 'Cash',
    cashless: 'Cashless',
    split: 'Split',
};
