export type StoreCloseMoneyPair = {
    cash: string;
    cashless: string;
};

export type StoreCloseOutstandingOrder = {
    id: string;
    order_number: string;
    customer_label: string | null;
    table_name: string | null;
    outstanding: string;
    payment_status: 'unpaid' | 'partial';
    payment_term: 'immediate' | 'pay_later' | null;
};

export type StoreCloseKitchenOrder = {
    id: string;
    order_number: string;
    customer_label: string | null;
    table_name: string | null;
    kitchen_status: 'kitchen' | 'preparing' | 'ready';
};

export type StoreCloseLoadedQr = {
    id: string;
    qr_number: string | null;
    customer_label: string | null;
    loaded_by: string | null;
};

export type StoreCloseCorrection = {
    id: string;
    order_id: string;
    order_number: string;
    customer_label: string | null;
    amount: string;
    cash_available: string;
    cashless_available: string;
    min_cash: string;
    max_cash: string;
    created_at: string | null;
};

/** Server-derived reconciliation; deductions are positive magnitudes and expected balances are signed. */
export type StoreCloseReconciliation = {
    opening: StoreCloseMoneyPair;
    sales: StoreCloseMoneyPair & { total: string; count: number };
    split: StoreCloseMoneyPair & { count: number };
    expenses: StoreCloseMoneyPair & { count: number };
    corrections: StoreCloseMoneyPair & { count: number };
    voids: StoreCloseMoneyPair & { count: number };
    expected: StoreCloseMoneyPair;
};

export type StoreClosePreview = {
    store_session: {
        id: string;
        opened_at: string;
        opened_by: { name: string };
        opening_cash_amount: string;
        opening_cashless_amount: string;
        branch: { id: string; code: string; name: string };
    };
    ready: boolean;
    blockers: {
        outstanding: {
            count: number;
            total: string;
            orders: StoreCloseOutstandingOrder[];
        };
        kitchen: { count: number; orders: StoreCloseKitchenOrder[] };
        loaded_qr: { count: number; orders: StoreCloseLoadedQr[] };
        corrections: { count: number; items: StoreCloseCorrection[] };
    };
    qr: { unclaimed_count: number };
    reconciliation: StoreCloseReconciliation | null;
    generated_at: string;
};

export type StoreCloseResult = {
    replayed: boolean;
    store_session: {
        id: string;
        status: 'closed';
        branch: { code: string; name: string };
        opened_at: string;
        closed_at: string;
        closed_by: { name: string | null };
        opening_cash_amount: string;
        opening_cashless_amount: string;
        expected_cash_amount: string;
        expected_cashless_amount: string;
        closing_cash_amount: string;
        closing_cashless_amount: string;
        cash_variance: string;
        cashless_variance: string;
        closing_note: string | null;
        qr_archived_count: number;
    };
};

export type StoreClosedRealtimeEvent = {
    event_id: string;
    event_type: 'store.closed';
    branch_id: string;
    store_session_id: string;
    closed_at: string | null;
    closed_by_user_id: number | null;
    state: 'closed';
    occurred_at: string;
};
