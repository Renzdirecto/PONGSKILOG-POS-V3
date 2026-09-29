import type { CurrentStoreSession } from '@/types';

export type StoreSessionDialogState = {
    open: boolean;
    session: CurrentStoreSession | null;
    loadState: StoreSessionLoadState;
};

export type StoreSessionLoadState =
    | 'idle'
    | 'loading'
    | 'loaded'
    | 'not_found'
    | 'forbidden'
    | 'session_expired'
    | 'offline'
    | 'error';

const storeSessionLoadMessages: Partial<Record<StoreSessionLoadState, string>> = {
    not_found: 'This Store Session is no longer available.',
    forbidden: 'You do not have permission to view this Store Session.',
    session_expired:
        'Your session has expired. Sign in or refresh before continuing.',
    offline:
        'Unable to load the Store Session. Check your connection and try again.',
    error: 'Store Session details could not be loaded. Try again.',
};

const pesoFormatter = new Intl.NumberFormat('en-PH', {
    style: 'currency',
    currency: 'PHP',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

const manilaDateTimeFormatter = new Intl.DateTimeFormat('en-PH', {
    timeZone: 'Asia/Manila',
    year: 'numeric',
    month: 'long',
    day: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
});

export function formatStoreSessionMoney(amount: string): string {
    return pesoFormatter.format(Number(amount));
}

export function formatStoreSessionOpenedAt(openedAt: string): string {
    return manilaDateTimeFormatter.format(new Date(openedAt));
}

/** Reopening shows the last confirmed session instantly while the server refresh runs. */
export function openStoreSessionDialogState(
    cached: CurrentStoreSession | null = null,
): StoreSessionDialogState {
    return {
        open: true,
        session: cached,
        loadState: 'loading',
    };
}

/** A missing, forbidden or expired session must never keep showing cached details. */
export function discardsStoreSession(state: StoreSessionLoadState): boolean {
    return (
        state === 'not_found' ||
        state === 'forbidden' ||
        state === 'session_expired'
    );
}

export function storeSessionLoadFailure(reason: unknown): StoreSessionLoadState {
    if (typeof reason === 'object' && reason !== null) {
        const failure = reason as {
            name?: unknown;
            response?: { status?: unknown };
        };
        const status = failure.response?.status;

        if (status === 404) return 'not_found';
        if (status === 403) return 'forbidden';
        if (status === 401 || status === 419) return 'session_expired';
        if (failure.name === 'HttpNetworkError') return 'offline';
    }

    if (typeof navigator !== 'undefined' && navigator.onLine === false) {
        return 'offline';
    }

    return 'error';
}

export function storeSessionLoadMessage(
    state: StoreSessionLoadState,
): string | null {
    return storeSessionLoadMessages[state] ?? null;
}

export function canRetryStoreSessionLoad(state: StoreSessionLoadState): boolean {
    return state === 'offline' || state === 'error';
}

export function storeSessionDetailRows(session: CurrentStoreSession) {
    return [
        {
            label: 'Opened',
            value: formatStoreSessionOpenedAt(session.opened_at),
        },
        {
            label: 'Opening Cash',
            value: formatStoreSessionMoney(session.opening_cash_amount),
        },
        {
            label: 'Opening Cashless',
            value: formatStoreSessionMoney(session.opening_cashless_amount),
        },
        {
            label: 'Branch',
            value: `${session.branch.code} · ${session.branch.name}`,
        },
        { label: 'Opened by', value: session.opened_by.name },
    ];
}
