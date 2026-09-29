/**
 * Store Session Stock Correction: move the system Product stock up or down so it matches the physical count. A free
 * Product given away is a Giveaway, never a Stock Correction, and an increase is never a purchase or restock.
 */
export type StockCorrectionDirection = 'decrease' | 'increase';

export type StockCorrectionReason =
    | 'physical_count'
    | 'found_stock'
    | 'missing_stock'
    | 'wastage'
    | 'damaged'
    | 'other';

/** Stable server reason codes, cashier-facing labels and the directions each one can explain. */
export const STOCK_CORRECTION_REASONS: {
    value: StockCorrectionReason;
    label: string;
    directions: StockCorrectionDirection[];
}[] = [
    {
        value: 'physical_count',
        label: 'Physical count / discrepancy',
        directions: ['decrease', 'increase'],
    },
    { value: 'found_stock', label: 'Found stock', directions: ['increase'] },
    { value: 'missing_stock', label: 'Missing stock', directions: ['decrease'] },
    { value: 'wastage', label: 'Wastage', directions: ['decrease'] },
    { value: 'damaged', label: 'Damaged', directions: ['decrease'] },
    { value: 'other', label: 'Other', directions: ['decrease', 'increase'] },
];

export function stockCorrectionReasons(direction: StockCorrectionDirection) {
    return STOCK_CORRECTION_REASONS.filter((reason) =>
        reason.directions.includes(direction),
    );
}

/** Integer-only preview; the server re-reads and locks the authoritative stock before writing. */
export function stockCorrectionPreview(
    onHand: number | null,
    direction: StockCorrectionDirection,
    quantityInput: string,
): {
    valid: boolean;
    quantity: number;
    delta: number;
    after: number;
    message: string | null;
} {
    const trimmed = quantityInput.trim();
    const quantity = /^\d+$/.test(trimmed) ? Number(trimmed) : NaN;
    const unchanged = { quantity: 0, delta: 0, after: onHand ?? 0 };
    if (trimmed === '') {
        return { valid: false, ...unchanged, message: null };
    }
    if (!Number.isSafeInteger(quantity) || quantity < 1) {
        return {
            valid: false,
            ...unchanged,
            message: 'Enter a whole-number quantity of at least 1.',
        };
    }
    if (quantity > 1_000_000) {
        return {
            valid: false,
            ...unchanged,
            message: 'Enter a quantity of at most 1,000,000.',
        };
    }
    if (direction === 'decrease' && onHand !== null && quantity > onHand) {
        return {
            valid: false,
            quantity,
            delta: 0,
            after: onHand,
            message: `Only ${onHand} in stock. Stock cannot go below zero.`,
        };
    }
    const delta = direction === 'increase' ? quantity : -quantity;

    return {
        valid: onHand !== null,
        quantity,
        delta,
        after: (onHand ?? 0) + delta,
        message: null,
    };
}

export function stockCorrectionError(error: unknown): string {
    const response =
        typeof error === 'object' && error !== null && 'response' in error
            ? (error as { response?: { status?: number; data?: unknown } })
                  .response
            : undefined;
    if (!response?.status) {
        return 'The Stock Correction could not be saved. Reconnect and try again.';
    }
    let data = response.data;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data) as unknown;
        } catch {
            data = null;
        }
    }
    if (response.status === 409) {
        return 'This Stock Correction attempt was already used with different details. Review and save again.';
    }
    if (response.status === 403) {
        return 'You are not authorized to correct stock.';
    }
    if (typeof data === 'object' && data !== null && 'errors' in data) {
        const errors = (data as { errors?: Record<string, unknown> }).errors;
        const first = errors && Object.values(errors).flat()[0];
        if (typeof first === 'string') return first;
    }

    return 'The Stock Correction could not be saved. Check the details and try again.';
}

export type SessionActivity<Expense, Adjustment, Giveaway = never> =
    | { kind: 'expense'; item: Expense }
    | { kind: 'adjustment'; item: Adjustment }
    | { kind: 'giveaway'; item: Giveaway };

/** Newest-first session history: money expenses, stock-only corrections and giveaways in one timeline. */
export function sessionActivity<
    Expense extends { id: string; created_at: string },
    Adjustment extends { id: string; created_at: string },
    Giveaway extends { id: string; created_at: string } = never,
>(
    expenses: Expense[],
    adjustments: Adjustment[] = [],
    giveaways: Giveaway[] = [],
): SessionActivity<Expense, Adjustment, Giveaway>[] {
    return [
        ...expenses.map((item) => ({ kind: 'expense' as const, item })),
        ...adjustments.map((item) => ({ kind: 'adjustment' as const, item })),
        ...giveaways.map((item) => ({ kind: 'giveaway' as const, item })),
    ].sort(
        (left, right) =>
            Date.parse(right.item.created_at) -
            Date.parse(left.item.created_at),
    );
}
