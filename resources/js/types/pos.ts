import type { CashierCatalog } from './catalog';

export type PosProduct = CashierCatalog['products'][number];
export type OrderType = 'dine_in' | 'take_out';
export type BranchTable = { id: string; name: string };
export type OrderReservation = {
    id: string;
    order_number: string;
    reference_number: string;
    order_type: OrderType;
};
export type CartLine = {
    key: string;
    product: PosProduct;
    quantity: number;
    notes: string;
    modifiers: { group_id: string; option_id: string }[];
};
export type OrderSummary = {
    id: string;
    order_number: string | null;
    qr_number?: string | null;
    source?: string;
    reference_number: string | null;
    order_type: OrderType;
    table_name: string | null;
    customer_label: string | null;
    subtotal: string;
    total: string;
    items: {
        id: string;
        name: string;
        size_prefix?: string | null;
        display_name?: string;
        unit_price: string;
        quantity: number;
        line_total: string;
        notes: string | null;
        modifiers: {
            id: string;
            group_name: string;
            semantic_role?: 'size' | 'instruction' | null;
            name: string;
            price_delta: string;
            quantity: number;
        }[];
    }[];
};

export type PayLaterOrder = OrderSummary & {
    commercial_status: 'active';
    payment_status: 'unpaid';
    payment_term: 'pay_later';
    kitchen_status: 'kitchen';
    store_session_id: string;
    committed_at: string;
    cashier: string;
};

export type AdditionalQrItem = {
    product_id: string;
    quantity: number;
    notes: string;
    modifiers: CartLine['modifiers'];
};
export type PayLaterAttempt = {
    order_id: string;
    idempotency_key: string;
    qr_metadata?: { customer_label: string; branch_table_id: string | null };
    qr_additional_items?: AdditionalQrItem[];
    order_type?: OrderType;
    customer_label?: string;
    branch_table_id?: string | null;
    items?: {
        product_id: string;
        quantity: number;
        notes: string;
        modifiers: CartLine['modifiers'];
    }[];
};

export type PayLaterInput = Omit<PayLaterAttempt, 'order_id'>;

export type PaymentInput = {
    payment_method: 'cash' | 'cashless' | 'split';
    cash_received: string | null;
    cashless_amount: string | null;
};
export type PaymentAttempt = PaymentInput & {
    idempotency_key: string;
    qr_metadata?: { customer_label: string; branch_table_id: string | null };
    qr_additional_items?: AdditionalQrItem[];
    draft_order_id?: string;
    reserved_order_id?: string;
    order_type?: OrderType;
    customer_label?: string;
    branch_table_id?: string | null;
    items?: {
        product_id: string;
        quantity: number;
        notes: string;
        modifiers: CartLine['modifiers'];
    }[];
};
export type ReceiptSummary = OrderSummary & {
    commercial_status: 'active' | 'completed' | 'voided';
    voided_at: string | null;
    payment_status: 'paid' | 'unpaid' | 'partial';
    store_session_id: string;
    committed_at: string;
    paid_at: string | null;
    cashier: string | null;
    branch: {
        name: string;
        code: string;
        address: string | null;
        contact: string | null;
        footer?: string | null;
        show_logo?: boolean;
        logo_url?: string;
    };
    payments: {
        id: string;
        method: 'cash' | 'cashless';
        amount: string;
        amount_received: string | null;
        change_amount: string | null;
        payment_group_id: string | null;
        payment_context: string | null;
        invoice: { name: string; url: string } | null;
    }[];
};

export type PaidReceipt = ReceiptSummary & {
    payment_status: 'paid';
    paid_at: string;
    cashier: string;
};
