export type StoreExpenseRealtimeEvent = {
    event_id: string;
    event_type: 'store.expense_recorded';
    branch_id: string;
    expense_id: string;
    store_session_id: string;
    payment_source: 'cash' | 'cashless';
    amount: string;
    inventory_linked: boolean;
    occurred_at: string;
};

export function storeExpenseError(error: unknown): string {
    if (typeof error !== 'object' || error === null || !('response' in error)) {
        return 'The expense could not be saved. Reconnect and try again.';
    }

    const response = (error as {
        response?: { status?: number; data?: unknown };
    }).response;
    let data = response?.data;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data) as unknown;
        } catch {
            data = null;
        }
    }
    if (response?.status === 409) {
        return 'This save attempt was already used with different details. Start a new expense.';
    }
    if (typeof data === 'object' && data !== null && 'errors' in data) {
        const errors = (data as { errors?: Record<string, unknown> }).errors;
        const first = errors && Object.values(errors).flat()[0];
        if (typeof first === 'string') return first;
    }

    return 'The expense could not be saved. Check the details and try again.';
}

export function isExpenseWriteOnline(connectionStatus: string): boolean {
    return (
        typeof navigator !== 'undefined' &&
        navigator.onLine &&
        connectionStatus === 'connected'
    );
}
