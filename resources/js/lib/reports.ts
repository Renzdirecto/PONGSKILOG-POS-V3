export type ReportPreset =
    | 'today'
    | 'yesterday'
    | 'last_7_days'
    | 'last_30_days'
    | 'month'
    | 'last_12_months'
    | 'custom';

export type ReportFilters = {
    date?: ReportPreset;
    from?: string;
    to?: string;
    session?: string;
    order_types?: string[];
    payment_methods?: string[];
    cashiers?: (number | string)[];
};

export type ReportOrderFilters = Pick<
    ReportFilters,
    'order_types' | 'payment_methods' | 'cashiers'
>;

/** The Owner standalone Reports period tabs, backed by server presets. */
export const REPORT_TABS: readonly [ReportPreset, string][] = [
    ['today', 'Daily'],
    ['last_7_days', 'Weekly'],
    ['last_30_days', 'Monthly'],
    ['last_12_months', 'Yearly'],
    ['custom', 'Custom'],
];

/** The Owner standalone Dashboard reporting-period tabs. */
export const DASHBOARD_PERIODS: readonly [
    'today' | 'last_7_days' | 'last_30_days',
    string,
][] = [
    ['today', 'Today'],
    ['last_7_days', '7 days'],
    ['last_30_days', '30 days'],
];

export type ReportSessionResult =
    | 'live'
    | 'balanced'
    | 'overage'
    | 'shortage'
    | 'unavailable';

/** Mirrors the server limit; the server re-validates every range. */
export const MAX_CUSTOM_REPORT_DAYS = 31;

export const REPORT_PRESETS: readonly [ReportPreset, string][] = [
    ['today', 'Today'],
    ['yesterday', 'Yesterday'],
    ['last_7_days', 'Last 7 days'],
    ['month', 'This month'],
    ['custom', 'Custom'],
];

/**
 * The next shareable report query. Changing the period clears the Store Session selection because
 * session options belong to the selected business dates; only Custom keeps a from/to pair.
 */
export function reportQuery(
    current: ReportFilters,
    next: ReportFilters,
): Record<string, string | string[]> {
    const periodChanged = 'date' in next || 'from' in next || 'to' in next;
    const merged: ReportFilters = {
        ...current,
        ...(periodChanged ? { session: undefined } : {}),
        ...next,
    };
    if (merged.date !== 'custom') {
        merged.from = undefined;
        merged.to = undefined;
    }

    return Object.fromEntries(
        Object.entries(merged).flatMap(
            ([key, value]): [string, string | string[]][] => {
                if (Array.isArray(value)) {
                    return value.length > 0
                        ? [[key, value.map((item) => String(item))]]
                        : [];
                }

                return typeof value === 'string' &&
                    value !== '' &&
                    !(key === 'date' && value === 'today')
                    ? [[key, value]]
                    : [];
            },
        ),
    );
}

/** Toggles one value in an order-filter list; an empty list means "all". */
export function toggleFilterValue<T extends string | number>(
    values: readonly T[],
    value: T,
): T[] {
    return values.includes(value)
        ? values.filter((item) => item !== value)
        : [...values, value];
}

function calendarDay(value: string): number | null {
    const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
    if (!match) {
        return null;
    }
    const time = Date.UTC(
        Number(match[1]),
        Number(match[2]) - 1,
        Number(match[3]),
    );

    return new Date(time).toISOString().startsWith(value)
        ? time / 86_400_000
        : null;
}

/** A friendly pre-check for a custom business-date range, or null when it can be submitted. */
export function customRangeError(from: string, to: string): string | null {
    const start = calendarDay(from);
    const end = calendarDay(to);
    if (start === null || end === null) {
        return 'Choose both a start and an end date.';
    }
    if (end < start) {
        return 'The end date must be on or after the start date.';
    }
    if (end - start + 1 > MAX_CUSTOM_REPORT_DAYS) {
        return `Choose a custom range of ${MAX_CUSTOM_REPORT_DAYS} days or fewer.`;
    }

    return null;
}

export const SESSION_RESULTS: Record<
    ReportSessionResult,
    { label: string; tone: 'blue' | 'green' | 'amber' | 'red' | 'neutral' }
> = {
    live: { label: 'Live', tone: 'blue' },
    balanced: { label: 'Balanced', tone: 'green' },
    overage: { label: 'Overage', tone: 'amber' },
    shortage: { label: 'Shortage', tone: 'red' },
    unavailable: { label: 'Not available', tone: 'neutral' },
};

export const VARIANCE_LABELS: Record<string, string> = {
    balanced: 'Balanced',
    overage: 'Overage',
    shortage: 'Shortage',
};
