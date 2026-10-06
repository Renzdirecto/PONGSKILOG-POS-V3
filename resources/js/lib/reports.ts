export type ReportPreset =
    /** The live Store Session of the scope, otherwise the latest one. The default Reports view. */
    | 'session'
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
    /** Category UUIDs or "uncategorized"; narrows the product views only. */
    categories?: string[];
};

/** Every filter the Filter this report dialog applies; an empty list means every value. */
export type ReportOrderFilters = Pick<
    ReportFilters,
    'order_types' | 'payment_methods' | 'cashiers' | 'categories'
>;

/**
 * The standalone filter dialog lists every value checked when a group is unfiltered: an empty server filter opens
 * with every option checked.
 */
export function draftSelection<T extends string | number>(
    active: readonly T[],
    options: readonly { value: T }[],
): T[] {
    return active.length === 0
        ? options.map((option) => option.value)
        : [...active];
}

/** Applying a group with nothing or everything checked removes that filter, like the standalone. */
export function appliedSelection<T extends string | number>(
    draft: readonly T[],
    options: readonly { value: T }[],
): T[] {
    const checked = options
        .map((option) => option.value)
        .filter((value) => draft.includes(value));

    return checked.length === 0 || checked.length === options.length
        ? []
        : checked;
}

/** The Owner standalone Reports period tabs, backed by server presets. Session is the default. */
export const REPORT_TABS: readonly [ReportPreset, string][] = [
    ['session', 'Session'],
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

/** Mirrors `ReportPeriod::DEFAULT_PRESET`: the default period is left out of the shareable query. */
export const DEFAULT_REPORT_PRESET: ReportPreset = 'session';

/**
 * The next shareable report query. Changing the period clears the Store Session selection because
 * session options belong to the selected business dates; only Custom keeps a from/to pair.
 * The Session period keeps its selection: that selection *is* the period.
 */
export function reportQuery(
    current: ReportFilters,
    next: ReportFilters,
): Record<string, string | string[]> {
    const periodChanged = 'date' in next || 'from' in next || 'to' in next;
    const merged: ReportFilters = {
        ...current,
        ...(periodChanged && next.date !== 'session'
            ? { session: undefined }
            : {}),
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
                    !(key === 'date' && value === DEFAULT_REPORT_PRESET)
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

/** Hours and minutes of an open Store Session, from server timestamps only. */
export function sessionElapsedLabel(seconds: number): string {
    const minutes = Math.max(0, Math.floor(seconds / 60));

    return minutes < 60
        ? `${minutes}m`
        : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export type SessionTiming = {
    status: 'open' | 'closed';
    opened_at: string;
    opened_at_time: string;
    closed_at_time: string | null;
    time_range: string;
    duration_seconds: number | null;
};

/**
 * How long a Store Session has been open: a LIVE session is measured from its server `opened_at` up to now, a CLOSED
 * one shows its server time range and persisted duration. No time is ever invented.
 */
export function sessionTimeline(session: SessionTiming, now: number): string {
    if (session.status === 'open') {
        const elapsed = Math.max(
            0,
            (now - new Date(session.opened_at).getTime()) / 1000,
        );

        return `Opened ${session.opened_at_time} · Live ${sessionElapsedLabel(elapsed)}`;
    }

    return session.duration_seconds === null
        ? session.time_range
        : `${session.time_range} · ${sessionElapsedLabel(session.duration_seconds)}`;
}

export const VARIANCE_LABELS: Record<string, string> = {
    balanced: 'Balanced',
    overage: 'Overage',
    shortage: 'Shortage',
};
