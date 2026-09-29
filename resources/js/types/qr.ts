import type { OrderSummary, OrderType, CartLine, PosProduct } from './pos';
import type { CanonicalReceipt } from './receipt';
export type QrProduct = Omit<
    PosProduct,
    'on_hand' | 'tracks_inventory' | 'stock_status'
> & { stock_status: 'available' | 'unavailable' | 'out_of_stock' };
export type QrLine = Omit<CartLine, 'product'> & { product: QrProduct };
export type QrOrder = {
    public_tracking_id: string;
    order_number: string | null;
    qr_number: string | null;
    reference_number: string | null;
    preparing_at: string | null;
    ready_at: string | null;
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
    voided_at: string | null;
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
/** The Customer QR session's receipt: its tracking projection plus the canonical receipt document. */
export type QrReceipt = Omit<QrOrder, keyof CanonicalReceipt> & CanonicalReceipt;
export type StaffQrOrder = OrderSummary & {
    source: 'customer_qr';
    qr_number: string | null;
    commercial_status: string;
    submitted_at: string;
    archived_at: string | null;
    archive_reason: string | null;
    branch_table_id: string | null;
    version: number;
    /** Archived tab only: whether Restore can apply (the order belongs to the open Store Session). */
    restorable?: boolean;
};

/** The shared receipt link / Pickup receipt: the canonical receipt document and when the link expires. */
export type PublicReceipt = CanonicalReceipt & {
    receipt_expires_at: string | null;
};
