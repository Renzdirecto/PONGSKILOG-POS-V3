export type ReportPreset =
    | 'today'
    | 'yesterday'
    | 'last_7_days'
    | 'month'
    | 'custom';

export type ReportFilters = {
    date?: ReportPreset;
    from?: string;
    to?: string;
    session?: string;
};

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
): Record<string, string> {
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
        Object.entries(merged).filter(
            (entry): entry is [string, string] =>
                typeof entry[1] === 'string' &&
                entry[1] !== '' &&
                !(entry[0] === 'date' && entry[1] === 'today'),
        ),
    );
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
