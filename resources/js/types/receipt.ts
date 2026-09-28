import type { OrderType } from './pos';

/** Every block a receipt can print, in the fixed zones of `App\Support\ReceiptLayout`. */
export type ReceiptBlock =
    | 'logo'
    | 'store'
    | 'address'
    | 'contact'
    | 'header_text'
    | 'order'
    | 'date'
    | 'cashier'
    | 'customer'
    | 'items'
    | 'totals'
    | 'payments'
    | 'custom_rows'
    | 'footer'
    | 'order_qr';

export type ReceiptDetailBlock = 'order' | 'date' | 'cashier' | 'customer';
export type ReceiptFooterBlock = 'custom_rows' | 'footer' | 'order_qr';
export type ReceiptSeparator = 'dashed' | 'solid' | 'none';

/** A Branch's stored Receipt Settings layout (always normalized by the server). */
export type ReceiptLayoutSettings = {
    hidden: ReceiptBlock[];
    details: ReceiptDetailBlock[];
    footer: ReceiptFooterBlock[];
    header_text: string | null;
    custom_rows: string[];
    separator: ReceiptSeparator;
};

export type ReceiptBranch = {
    name: string;
    code: string;
    address: string | null;
    contact: string | null;
    footer: string | null;
    show_logo: boolean;
    logo_url: string;
};

export type ReceiptModifier = {
    id?: string;
    group_name: string;
    semantic_role?: 'size' | 'instruction' | null;
    name: string;
    price_delta: string;
    quantity: number;
};

export type ReceiptItem = {
    id?: string;
    name: string;
    size_prefix?: string | null;
    display_name?: string;
    quantity: number;
    unit_price: string;
    line_total: string;
    notes: string | null;
    modifiers: ReceiptModifier[];
};

export type ReceiptPayment = {
    id?: string;
    method: 'cash' | 'cashless';
    amount: string;
    amount_received: string | null;
    change_amount: string | null;
};

/**
 * THE receipt contract (`App\Support\ReceiptDocument`): the same shape for the POS print, Transaction History reprint,
 * shared receipt link, Customer QR receipt, Pickup receipt and the Receipt Settings preview. The server has already
 * removed what the viewer may not see and resolved which blocks the Branch shows.
 */
export type CanonicalReceipt = {
    order_number: string | null;
    reference_number: string | null;
    order_type: OrderType;
    customer_label: string | null;
    table_name: string | null;
    commercial_status: string;
    payment_status: string;
    committed_at: string | null;
    paid_at: string | null;
    cashier: string | null;
    subtotal: string;
    total: string;
    money: { paid: string; refunded: string; balance: string };
    branch: ReceiptBranch;
    layout: {
        blocks: ReceiptBlock[];
        separator: ReceiptSeparator;
        header_text: string | null;
        custom_rows: string[];
        order_qr: { url: string; image: string } | null;
    };
    items: ReceiptItem[];
    payments: ReceiptPayment[];
};
