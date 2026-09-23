import type { StoreCloseMoneyPair } from '@/types/store-close';

/** Matches the server: non-negative, up to 12 integer digits and 2 decimal places. */
const MONEY_INPUT = /^\d{1,12}(?:\.\d{1,2})?$/;

export const MINIMUM_OVERAGE_NOTE_LENGTH = 5;

/** Operational signals that can change pre-close blockers or reconciliation totals. */
export const STORE_CLOSE_POS_EVENTS = [
    '.order.committed',
    '.order.updated',
    '.order.voided',
    '.kitchen.ticket_created',
    '.kitchen.status_changed',
    '.qr.order_submitted',
    '.qr.order_loaded',
    '.qr.order_archived',
    '.qr.order_released',
    '.qr.order_restored',
] as const;

export type VarianceState =
    | 'pending'
    | 'invalid'
    | 'exact'
    | 'shortage'
    | 'overage';

export type ChannelAssessment = {
    state: VarianceState;
    actual: bigint | null;
    variance: bigint | null;
};

export type CloseAssessment = {
    cash: ChannelAssessment;
    cashless: ChannelAssessment;
    hasShortage: boolean;
    needsNote: boolean;
    noteValid: boolean;
    canContinue: boolean;
};

export type StoreCloseErrorKind =
    | 'blocker'
    | 'validation'
    | 'closed'
    | 'conflict'
    | 'forbidden'
    | 'network'
    | 'error';

const BLOCKER_FIELDS = ['outstanding', 'kitchen', 'loaded_qr', 'corrections'];

/** Exact signed cents from a server decimal string such as "-250.00". */
export function signedCents(value: string): bigint {
    const negative = value.startsWith('-');
    const [whole, fraction = ''] = value.replace(/^-/, '').split('.');
    const cents =
        BigInt(whole || '0') * 100n +
        BigInt(fraction.padEnd(2, '0').slice(0, 2));

    return negative ? -cents : cents;
}

export function formatPeso(cents: bigint): string {
    const magnitude = cents < 0n ? -cents : cents;
    const whole = (magnitude / 100n)
        .toString()
        .replace(/\B(?=(\d{3})+(?!\d))/g, ',');

    return `${cents < 0n ? '-' : ''}₱${whole}.${(magnitude % 100n).toString().padStart(2, '0')}`;
}

export function formatDecimalPeso(value: string): string {
    return formatPeso(signedCents(value));
}

/** Accepts "1,400" or " 1400.5 " and returns the canonical request value, or null when invalid. */
export function normalizeMoneyInput(value: string): string | null {
    const compact = value.replace(/[,\s]/g, '');
    if (!MONEY_INPUT.test(compact)) {
        return null;
    }
    const [whole, fraction = ''] = compact.split('.');

    return `${BigInt(whole).toString()}.${fraction.padEnd(2, '0')}`;
}

/** variance = actual − expected; negative is a shortage and positive an overage. */
export function assessChannel(
    expected: string,
    actualInput: string,
): ChannelAssessment {
    if (actualInput.trim() === '') {
        return { state: 'pending', actual: null, variance: null };
    }
    const normalized = normalizeMoneyInput(actualInput);
    if (normalized === null) {
        return { state: 'invalid', actual: null, variance: null };
    }
    const actual = signedCents(normalized);
    const variance = actual - signedCents(expected);

    return {
        state:
            variance === 0n ? 'exact' : variance < 0n ? 'shortage' : 'overage',
        actual,
        variance,
    };
}

export function assessClose(
    expected: StoreCloseMoneyPair,
    cashInput: string,
    cashlessInput: string,
    note: string,
): CloseAssessment {
    const cash = assessChannel(expected.cash, cashInput);
    const cashless = assessChannel(expected.cashless, cashlessInput);
    const states = [cash.state, cashless.state];
    const hasShortage = states.includes('shortage');
    const needsNote = !hasShortage && states.includes('overage');
    const noteValid = note.trim().length >= MINIMUM_OVERAGE_NOTE_LENGTH;
    const settled = states.every(
        (state) => state === 'exact' || state === 'overage',
    );

    return {
        cash,
        cashless,
        hasShortage,
        needsNote,
        noteValid,
        canContinue: settled && (!needsNote || noteValid),
    };
}

/**
 * Feasible Cash portion of a lower-total refund given what each method can still return.
 * When min equals max the source is deterministic; otherwise the Cashier must choose.
 */
export function correctionRefundBounds(
    refund: bigint,
    cashAvailable: string,
    cashlessAvailable: string,
): { min: bigint; max: bigint } {
    const cash = signedCents(cashAvailable);
    const cashless = signedCents(cashlessAvailable);
    const min = refund - cashless > 0n ? refund - cashless : 0n;
    const max = refund < cash ? refund : cash;

    return { min, max: max < min ? min : max };
}

export function shortageMessage(
    label: 'Cash' | 'Cashless',
    variance: bigint,
): string {
    return `Closing ${label} is ${formatPeso(variance < 0n ? -variance : variance)} short. Review the count or missing transactions before closing.`;
}

/** Stable request identity: a changed count or note must use a new idempotency key. */
export function closeAttemptSignature(
    sessionId: string,
    cash: string,
    cashless: string,
    note: string | null,
): string {
    return JSON.stringify([sessionId, cash, cashless, note ?? '']);
}

export function isBrowserOnline(): boolean {
    return typeof navigator === 'undefined' || navigator.onLine;
}

function responseData(response: { data?: unknown }): Record<string, unknown> {
    let data = response.data;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data) as unknown;
        } catch {
            data = null;
        }
    }

    return typeof data === 'object' && data !== null
        ? (data as Record<string, unknown>)
        : {};
}

export function storeCloseError(error: unknown): {
    kind: StoreCloseErrorKind;
    message: string;
} {
    const response =
        typeof error === 'object' && error !== null && 'response' in error
            ? (error as { response?: { status?: number; data?: unknown } })
                  .response
            : undefined;
    if (!response?.status) {
        return {
            kind: 'network',
            message:
                'The connection dropped before the server confirmed the close. Reconnect and retry — this same attempt cannot close the Store twice.',
        };
    }
    const data = responseData(response);
    const message = typeof data.message === 'string' ? data.message : null;
    if (response.status === 403) {
        return {
            kind: 'forbidden',
            message: 'You are not authorized to close this Store.',
        };
    }
    if (response.status === 409) {
        return {
            kind: message?.includes('already closed') ? 'closed' : 'conflict',
            message:
                message ??
                'The Store Session changed. Refresh the closing summary and try again.',
        };
    }
    if (response.status === 422) {
        const errors = (data.errors ?? {}) as Record<string, unknown>;
        const fields = Object.keys(errors);
        const first = Object.values(errors).flat()[0];

        return {
            kind: fields.some((field) => BLOCKER_FIELDS.includes(field))
                ? 'blocker'
                : 'validation',
            message:
                typeof first === 'string'
                    ? first
                    : 'Check the closing values and try again.',
        };
    }

    return {
        kind: 'error',
        message:
            'The Store could not be closed. Nothing was changed. Try again.',
    };
}
