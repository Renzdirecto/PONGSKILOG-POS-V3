import type {
    CanonicalReceipt,
    ReceiptBlock,
    ReceiptDetailBlock,
    ReceiptFooterBlock,
    ReceiptItem,
    ReceiptLayoutSettings,
    ReceiptPayment,
} from '../types/receipt';
import { cents, pesos } from './pos-money';

/** The thank-you line every receipt prints when the Branch has not written its own. */
export const DEFAULT_RECEIPT_FOOTER = 'Salamat po! Come again.';

export const RECEIPT_HEADER_BLOCKS: ReceiptBlock[] = [
    'logo',
    'store',
    'address',
    'contact',
    'header_text',
];
export const RECEIPT_BODY_BLOCKS: ReceiptBlock[] = [
    'items',
    'totals',
    'payments',
];
export const RECEIPT_DETAIL_BLOCKS: ReceiptDetailBlock[] = [
    'order',
    'date',
    'cashier',
    'customer',
];
export const RECEIPT_FOOTER_BLOCKS: ReceiptFooterBlock[] = [
    'custom_rows',
    'footer',
    'order_qr',
];
/** Mirrors `ReceiptLayout::REQUIRED`: always printed. */
export const RECEIPT_REQUIRED_BLOCKS: ReceiptBlock[] = [
    'store',
    'order',
    'items',
    'totals',
];
export const RECEIPT_MAX_CUSTOM_ROWS = 5;
export const RECEIPT_MAX_TEXT = 120;

export const RECEIPT_BLOCK_LABELS: Record<ReceiptBlock, string> = {
    logo: 'Logo',
    store: 'Store name',
    address: 'Address',
    contact: 'Contact',
    header_text: 'Header text',
    order: 'Order number and reference',
    date: 'Date and time',
    cashier: 'Cashier (Preferred Name)',
    customer: 'Customer / table',
    items: 'Items with options and instructions',
    totals: 'Subtotal and total',
    payments: 'Payment breakdown',
    custom_rows: 'Custom text rows',
    footer: 'Thank-you message',
    order_qr: 'Order-again QR code',
};

export type ReceiptZone = 'header' | 'details' | 'body' | 'footer';

/** The zone of a block: zones always print Header → Details → Items/Totals/Payments → Footer. */
export function receiptZone(block: ReceiptBlock): ReceiptZone {
    if (RECEIPT_HEADER_BLOCKS.includes(block)) return 'header';
    if ((RECEIPT_DETAIL_BLOCKS as ReceiptBlock[]).includes(block))
        return 'details';
    if (RECEIPT_BODY_BLOCKS.includes(block)) return 'body';
    return 'footer';
}

/**
 * The visible blocks as printed sections (a separator between sections): the header, details and footer blocks each
 * print together; items, totals and payments are each their own section.
 */
export function receiptSections(
    blocks: ReceiptBlock[],
): { zone: ReceiptZone; blocks: ReceiptBlock[] }[] {
    const sections: { zone: ReceiptZone; blocks: ReceiptBlock[] }[] = [];
    for (const block of blocks) {
        const zone = receiptZone(block);
        const last = sections.at(-1);
        if (last?.zone === zone && zone !== 'body') last.blocks.push(block);
        else sections.push({ zone, blocks: [block] });
    }
    return sections;
}

/** Mirrors `ReceiptLayout::blocks()` so the Settings preview shows exactly what will print. */
export function receiptLayoutBlocks(
    layout: ReceiptLayoutSettings,
    showLogo: boolean,
): ReceiptBlock[] {
    const visible = (block: ReceiptBlock) =>
        RECEIPT_REQUIRED_BLOCKS.includes(block) ||
        (block === 'logo' ? showLogo : !layout.hidden.includes(block));
    return [
        ...RECEIPT_HEADER_BLOCKS,
        ...layout.details,
        ...RECEIPT_BODY_BLOCKS,
        ...layout.footer,
    ].filter(visible);
}

/** The receipt moment in Manila time: when the order became fully paid, else when it was committed. */
export function receiptDateTime(
    receipt: Pick<CanonicalReceipt, 'paid_at' | 'committed_at'>,
): string {
    const value = receipt.paid_at ?? receipt.committed_at;
    if (!value) return '—';
    return new Date(value).toLocaleString('en-PH', {
        timeZone: 'Asia/Manila',
        year: 'numeric',
        month: 'short',
        day: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
    });
}

export function receiptOrderType(orderType: string): string {
    return orderType === 'dine_in' ? 'Dine in' : 'Take out';
}

/** The status stamp: VOIDED wins, then the payment state. Literal capitals (a PNG export ignores CSS text-transform). */
export function receiptStatus(
    receipt: Pick<CanonicalReceipt, 'commercial_status' | 'payment_status'>,
): { label: string; tone: 'paid' | 'due' | 'unpaid' | 'voided' } {
    if (receipt.commercial_status === 'voided')
        return { label: 'VOIDED', tone: 'voided' };
    if (receipt.payment_status === 'paid')
        return { label: 'PAID', tone: 'paid' };
    if (receipt.payment_status === 'partial')
        return { label: 'BALANCE DUE', tone: 'due' };
    return { label: 'UNPAID', tone: 'unpaid' };
}

/** Customer name and table once each ("Juan / Table 4"), or '' when the audience does not show them. */
export function receiptCustomer(
    receipt: Pick<CanonicalReceipt, 'customer_label' | 'table_name'>,
): string {
    return Array.from(
        new Set(
            [receipt.customer_label, receipt.table_name].filter(
                (value): value is string => Boolean(value),
            ),
        ),
    ).join(' / ');
}

/** Paid add-ons of an item ("Extra rice (+₱20.00)"); the size is the item's own prefix. */
export function receiptAddOns(item: Pick<ReceiptItem, 'modifiers'>): string[] {
    return item.modifiers
        .filter(
            (modifier) =>
                modifier.semantic_role !== 'size' &&
                modifier.semantic_role !== 'instruction',
        )
        .map((modifier) =>
            cents(modifier.price_delta) > 0n
                ? `${modifier.name} (+${pesos(modifier.price_delta)})`
                : modifier.name,
        );
}

/** Structured instructions and the free-text note, in that order, as one line. */
export function receiptInstructions(
    item: Pick<ReceiptItem, 'modifiers' | 'notes'>,
): string {
    const details = item.modifiers
        .filter((modifier) => modifier.semantic_role === 'instruction')
        .map((modifier) => modifier.name);
    const note = item.notes?.trim();
    if (note) details.push(note);
    return details.join(', ');
}

/** The payment method line of the whole order ("Cash", "Cashless", "Split · Cash + Cashless"). */
export function receiptPaymentMethod(payments: ReceiptPayment[]): string {
    const methods = new Set(payments.map((payment) => payment.method));
    if (methods.size === 0) return 'Not paid yet';
    if (methods.size > 1) return 'Split · Cash + Cashless';
    return methods.has('cash') ? 'Cash' : 'Cashless';
}

export type ReceiptPaymentRow = {
    label: string;
    value: string;
    strong?: boolean;
};

/**
 * Every payment of the order in the order it was taken (an edited order's later payments included), each cash payment
 * with its amount received and change.
 */
export function receiptPaymentRows(
    payments: ReceiptPayment[],
): ReceiptPaymentRow[] {
    const split = new Set(payments.map((payment) => payment.method)).size > 1;
    const several = payments.length > 1;
    return payments.flatMap((payment): ReceiptPaymentRow[] => {
        const label = payment.method === 'cash' ? 'Cash' : 'Cashless';
        const rows: ReceiptPaymentRow[] =
            several || split
                ? [{ label, value: pesos(payment.amount) }]
                : [];
        if (payment.method === 'cash' && payment.amount_received !== null) {
            rows.push(
                {
                    label: 'Amount received',
                    value: pesos(payment.amount_received),
                },
                {
                    label: 'Change',
                    value: pesos(payment.change_amount ?? '0.00'),
                    strong: true,
                },
            );
        }
        return rows;
    });
}

/** Refunds and the remaining balance, only when there are any. */
export function receiptBalanceRows(
    receipt: Pick<CanonicalReceipt, 'money' | 'payment_status' | 'commercial_status'>,
): ReceiptPaymentRow[] {
    const rows: ReceiptPaymentRow[] = [];
    if (cents(receipt.money.refunded) > 0n)
        rows.push({ label: 'Refunded', value: `−${pesos(receipt.money.refunded)}` });
    if (
        receipt.commercial_status !== 'voided' &&
        receipt.payment_status !== 'paid' &&
        cents(receipt.money.balance) > 0n
    ) {
        rows.push(
            { label: 'Paid', value: pesos(receipt.money.paid) },
            { label: 'Balance due', value: pesos(receipt.money.balance), strong: true },
        );
    }
    return rows;
}

export type ReceiptSettingsDraft = {
    receipt_name: string;
    receipt_address: string;
    receipt_contact: string;
    receipt_footer: string;
    receipt_show_logo: boolean;
};

export type ReceiptPreviewBranch = {
    name: string;
    code: string;
    address: string | null;
    contact: string | null;
    qr_ordering_enabled: boolean;
    qr_url: string;
    qr_image: string;
};

/**
 * The Receipt Settings preview: a sample order printed with the unsaved settings, resolved exactly like
 * `ReceiptDocument` (blank store texts fall back to the Branch details; hidden blocks carry no data).
 */
export function receiptPreview(
    branch: ReceiptPreviewBranch,
    draft: ReceiptSettingsDraft,
    layout: ReceiptLayoutSettings,
    logoUrl: string,
): CanonicalReceipt {
    const blocks = receiptLayoutBlocks(layout, draft.receipt_show_logo);
    const shows = (block: ReceiptBlock) => blocks.includes(block);
    const text = (value: string) => value.trim() || null;
    const customRows = layout.custom_rows
        .map((row) => row.trim())
        .filter(Boolean);

    return {
        order_number: '1045',
        reference_number: `${branch.code}-20260907-1045`,
        order_type: 'dine_in',
        customer_label: null,
        table_name: 'Table 4',
        commercial_status: 'completed',
        payment_status: 'paid',
        committed_at: '2026-09-07T12:30:00Z',
        paid_at: '2026-09-07T12:32:00Z',
        cashier: shows('cashier') ? 'Ana' : null,
        subtotal: '240.00',
        total: '240.00',
        money: { paid: '240.00', refunded: '0.00', balance: '0.00' },
        branch: {
            name: text(draft.receipt_name) ?? branch.name,
            code: branch.code,
            address: shows('address')
                ? (text(draft.receipt_address) ?? branch.address)
                : null,
            contact: shows('contact')
                ? (text(draft.receipt_contact) ?? branch.contact)
                : null,
            footer: shows('footer') ? text(draft.receipt_footer) : null,
            show_logo: draft.receipt_show_logo,
            logo_url: logoUrl,
        },
        layout: {
            blocks,
            separator: layout.separator,
            header_text: shows('header_text')
                ? text(layout.header_text ?? '')
                : null,
            custom_rows: shows('custom_rows') ? customRows : [],
            order_qr:
                shows('order_qr') && branch.qr_ordering_enabled
                    ? { url: branch.qr_url, image: branch.qr_image }
                    : null,
        },
        items: [
            {
                name: 'Tapsilog',
                size_prefix: 'Large',
                display_name: 'Large Tapsilog',
                quantity: 2,
                unit_price: '110.00',
                line_total: '220.00',
                notes: null,
                modifiers: [
                    {
                        group_name: 'Size',
                        semantic_role: 'size',
                        name: 'Large',
                        price_delta: '0.00',
                        quantity: 1,
                    },
                    {
                        group_name: 'Add-ons',
                        semantic_role: null,
                        name: 'Extra egg',
                        price_delta: '15.00',
                        quantity: 1,
                    },
                    {
                        group_name: 'Instructions',
                        semantic_role: 'instruction',
                        name: 'Less rice',
                        price_delta: '0.00',
                        quantity: 1,
                    },
                ],
            },
            {
                name: 'Bottled Water',
                quantity: 1,
                unit_price: '20.00',
                line_total: '20.00',
                notes: null,
                modifiers: [],
            },
        ],
        payments: [
            {
                method: 'cash',
                amount: '240.00',
                amount_received: '300.00',
                change_amount: '60.00',
            },
        ],
    };
}

/** The layout as sent to Receipt Settings: trimmed texts, empty custom rows dropped. */
export function receiptLayoutPayload(
    layout: ReceiptLayoutSettings,
): ReceiptLayoutSettings {
    return {
        ...layout,
        header_text: layout.header_text?.trim() || null,
        custom_rows: layout.custom_rows
            .map((row) => row.trim())
            .filter(Boolean),
    };
}

/** Moves one block of a zone's order up (-1) or down (+1); out-of-range moves change nothing. */
export function moveReceiptBlock<T extends string>(
    order: T[],
    block: T,
    step: -1 | 1,
): T[] {
    const from = order.indexOf(block);
    const to = from + step;
    if (from < 0 || to < 0 || to >= order.length) return order;
    const next = [...order];
    [next[from], next[to]] = [next[to], next[from]];
    return next;
}
