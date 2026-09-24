export type InventoryAdjustmentReason =
    | 'complimentary'
    | 'wastage'
    | 'damaged'
    | 'staff_meal'
    | 'other';

/** Stable server reason codes with cashier-facing labels. */
export const INVENTORY_ADJUSTMENT_REASONS: {
    value: InventoryAdjustmentReason;
    label: string;
}[] = [
    { value: 'complimentary', label: 'Complimentary / Free item' },
    { value: 'wastage', label: 'Wastage' },
    { value: 'damaged', label: 'Damaged' },
    { value: 'staff_meal', label: 'Staff meal' },
    { value: 'other', label: 'Other' },
];

/** Integer-only preview; the server re-reads authoritative stock before writing. */
export function inventoryAdjustmentPreview(
    onHand: number | null,
    quantityInput: string,
): { valid: boolean; quantity: number; after: number; message: string | null } {
    const trimmed = quantityInput.trim();
    const quantity = /^\d+$/.test(trimmed) ? Number(trimmed) : NaN;
    if (trimmed === '') {
        return { valid: false, quantity: 0, after: onHand ?? 0, message: null };
    }
    if (!Number.isSafeInteger(quantity) || quantity < 1) {
        return {
            valid: false,
            quantity: 0,
            after: onHand ?? 0,
            message: 'Enter a whole-number quantity of at least 1.',
        };
    }
    if (onHand !== null && quantity > onHand) {
        return {
            valid: false,
            quantity,
            after: onHand,
            message: `Only ${onHand} in stock. Stock cannot go below zero.`,
        };
    }

    return {
        valid: onHand !== null,
        quantity,
        after: (onHand ?? 0) - quantity,
        message: null,
    };
}

export function inventoryAdjustmentError(error: unknown): string {
    const response =
        typeof error === 'object' && error !== null && 'response' in error
            ? (error as { response?: { status?: number; data?: unknown } })
                  .response
            : undefined;
    if (!response?.status) {
        return 'The adjustment could not be saved. Reconnect and try again.';
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
        return 'This adjustment attempt was already used with different details. Review and save again.';
    }
    if (response.status === 403) {
        return 'You are not authorized to adjust inventory.';
    }
    if (typeof data === 'object' && data !== null && 'errors' in data) {
        const errors = (data as { errors?: Record<string, unknown> }).errors;
        const first = errors && Object.values(errors).flat()[0];
        if (typeof first === 'string') return first;
    }

    return 'The adjustment could not be saved. Check the details and try again.';
}

export type SessionActivity<Expense, Adjustment, Giveaway = never> =
    | { kind: 'expense'; item: Expense }
    | { kind: 'adjustment'; item: Adjustment }
    | { kind: 'giveaway'; item: Giveaway };

/** Newest-first session history: money expenses, stock-only adjustments and giveaways in one timeline. */
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
