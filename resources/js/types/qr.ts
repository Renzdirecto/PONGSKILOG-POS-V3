import type { OrderSummary, OrderType, CartLine, PosProduct } from './pos';
export type QrProduct = Omit<
    PosProduct,
    'on_hand' | 'tracks_inventory' | 'stock_status'
> & { stock_status: 'available' | 'unavailable' | 'out_of_stock' };
export type QrLine = Omit<CartLine, 'product'> & { product: QrProduct };
export type QrOrder = {
    public_tracking_id: string;
    order_number: string;
    order_type: OrderType;
    customer_label: string | null;
    table_name: string | null;
    subtotal: string;
    total: string;
    commercial_status: string;
    payment_status: string;
    payment_term: string | null;
    kitchen_status: string;
    submitted_at: string;
    committed_at: string | null;
    completed_at: string | null;
    archived_at: string | null;
    paid_at: string | null;
    receipt_available: boolean;
    receipt_expires_at: string | null;
    version: number;
    items: (Omit<OrderSummary['items'][number], 'id' | 'modifiers'> & {
        modifiers: Omit<
            OrderSummary['items'][number]['modifiers'][number],
            'id'
        >[];
    })[];
};
export type QrReceipt = QrOrder & {
    branch: {
        name: string;
        code: string;
        address: string | null;
        contact: string | null;
    };
    payments: {
        method: string;
        amount: string;
        amount_received: string | null;
        change_amount: string | null;
    }[];
};
export type StaffQrOrder = OrderSummary & {
    source: 'customer_qr';
    commercial_status: string;
    submitted_at: string;
    archived_at: string | null;
    archive_reason: string | null;
    branch_table_id: string | null;
    version: number;
};
