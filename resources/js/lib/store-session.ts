import type { CurrentStoreSession } from '@/types';

export type StoreSessionDialogState = {
    open: boolean;
    session: CurrentStoreSession | null;
    unavailable: boolean;
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

export function openStoreSessionDialogState(): StoreSessionDialogState {
    return {
        open: true,
        session: null,
        unavailable: false,
    };
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
