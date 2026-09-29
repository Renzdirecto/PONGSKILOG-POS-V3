import type { Ref } from 'react';
import { OperationalItemName } from '@/components/operational-item-name';
import { pesos } from '@/lib/pos-money';
import {
    DEFAULT_RECEIPT_FOOTER,
    receiptAddOns,
    receiptBalanceRows,
    receiptCustomer,
    receiptDateTime,
    receiptInstructions,
    receiptOrderType,
    receiptPaymentMethod,
    receiptPaymentRows,
    receiptSections,
    receiptStatus,
} from '@/lib/receipt';
import type { CanonicalReceipt, ReceiptBlock } from '@/types/receipt';

const SEPARATORS = {
    dashed: 'border-t border-dashed border-neutral-300',
    solid: 'border-t border-solid border-neutral-400',
    none: '',
} as const;

const STATUS_STYLES = {
    paid: 'bg-neutral-950 text-white',
    due: 'border border-amber-200 bg-amber-50 text-amber-800',
    unpaid: 'border border-red-200 bg-red-50 text-red-700',
    voided: 'border border-red-200 bg-red-50 text-red-800',
} as const;

/**
 * THE receipt (Phase 20): every receipt surface — POS print, Transaction History reprint, the shared receipt link,
 * the Customer QR receipt, the Pickup receipt and the Receipt Settings preview — renders this one component from the
 * canonical `ReceiptDocument` contract, so they cannot drift. It prints only the blocks the server resolved from the
 * Branch's Receipt Settings, in their order. Always light paper (`theme-static`); text is literal (no CSS
 * text-transform) so the PNG export matches the screen.
 */
export function ReceiptDocument({
    receipt,
    variant = 'card',
    ref,
}: {
    receipt: CanonicalReceipt;
    /** `paper`: the narrow printed receipt (POS); `card`: the digital receipt (customer pages). */
    variant?: 'paper' | 'card';
    ref?: Ref<HTMLElement>;
}) {
    const separator = SEPARATORS[receipt.layout.separator];

    return (
        <article
            ref={ref}
            data-receipt-document
            aria-label={`Receipt for order ${receipt.order_number ?? ''}`}
            className={`theme-static flex flex-col bg-white text-xs text-neutral-950 tabular-nums ${variant === 'paper' ? 'mx-auto w-full max-w-95 rounded-md border border-neutral-200 px-4.5 py-5' : 'rounded-2xl border border-neutral-200 p-5'}`}
        >
            {receiptSections(receipt.layout.blocks).map((section, index) => (
                <section
                    key={`${section.zone}-${section.blocks[0]}`}
                    className={`flex flex-col gap-1.5 ${index > 0 ? `mt-3 pt-3 ${separator}` : ''} ${section.zone === 'header' || section.zone === 'footer' ? 'items-center text-center' : ''}`}
                >
                    {section.blocks.map((block) => (
                        <ReceiptBlockView
                            key={block}
                            block={block}
                            receipt={receipt}
                        />
                    ))}
                </section>
            ))}
        </article>
    );
}

function ReceiptBlockView({
    block,
    receipt,
}: {
    block: ReceiptBlock;
    receipt: CanonicalReceipt;
}) {
    const { branch, layout } = receipt;
    switch (block) {
        case 'logo':
            return (
                <img
                    src={branch.logo_url}
                    alt={branch.name}
                    className="mb-1 h-10 max-w-40 object-contain"
                />
            );
        case 'store':
            return (
                <h2 className="text-[15px] leading-5 font-bold wrap-anywhere">
                    {branch.name}
                </h2>
            );
        case 'address':
        case 'contact': {
            const value = block === 'address' ? branch.address : branch.contact;
            return value ? (
                <p className="text-[10.5px] leading-4 wrap-anywhere text-neutral-500">
                    {value}
                </p>
            ) : null;
        }
        case 'header_text':
            return layout.header_text ? (
                <p className="text-[11px] font-semibold wrap-anywhere">
                    {layout.header_text}
                </p>
            ) : null;
        case 'order': {
            const status = receiptStatus(receipt);
            return (
                <>
                    <div className="flex flex-col items-center gap-1 pb-1 text-center">
                        <p className="text-[20px] leading-6 font-bold tracking-tight wrap-anywhere text-red-700">
                            ORDER #{receipt.order_number ?? '—'}
                        </p>
                        <span
                            className={`rounded-full px-3 py-1 text-[10px] font-bold tracking-widest ${STATUS_STYLES[status.tone]}`}
                        >
                            {status.label}
                        </span>
                        {receipt.reference_number && (
                            <p className="text-[10.5px] break-all text-neutral-500">
                                Reference: {receipt.reference_number}
                            </p>
                        )}
                    </div>
                    <ReceiptRow
                        label="Order type"
                        value={receiptOrderType(receipt.order_type)}
                    />
                </>
            );
        }
        case 'date':
            return (
                <ReceiptRow
                    label="Date / time"
                    value={receiptDateTime(receipt)}
                />
            );
        case 'cashier':
            return receipt.cashier ? (
                <ReceiptRow label="Cashier" value={receipt.cashier} />
            ) : null;
        case 'customer': {
            const customer = receiptCustomer(receipt);
            return customer ? (
                <ReceiptRow label="Customer / table" value={customer} />
            ) : null;
        }
        case 'items':
            return (
                <div>
                    <h3 className="mb-1 text-[10px] font-bold tracking-wider">
                        ITEMS
                    </h3>
                    {receipt.items.map((item, index) => {
                        const instructions = receiptInstructions(item);
                        return (
                            <div
                                key={item.id ?? index}
                                className="flex gap-3 py-1.5"
                            >
                                <div className="min-w-0 flex-1">
                                    <p className="font-semibold wrap-anywhere">
                                        {item.quantity}&times;{' '}
                                        <OperationalItemName
                                            value={{
                                                name: item.name,
                                                sizePrefix:
                                                    item.size_prefix ?? null,
                                                displayName:
                                                    item.display_name ??
                                                    item.name,
                                            }}
                                        />
                                    </p>
                                    {receiptAddOns(item).map((addOn) => (
                                        <p
                                            key={addOn}
                                            className="pl-3 text-[10.5px] wrap-anywhere text-neutral-500"
                                        >
                                            {addOn}
                                        </p>
                                    ))}
                                    {instructions && (
                                        <p className="pl-3 text-[10.5px] wrap-anywhere text-amber-800">
                                            Instructions: {instructions}
                                        </p>
                                    )}
                                </div>
                                <p className="shrink-0 font-semibold">
                                    {pesos(item.line_total)}
                                </p>
                            </div>
                        );
                    })}
                </div>
            );
        case 'totals':
            return (
                <div className="flex flex-col gap-1.5">
                    <ReceiptRow
                        label="Subtotal"
                        value={pesos(receipt.subtotal)}
                    />
                    <p className="flex items-baseline justify-between gap-3 text-[15px] font-bold">
                        <span>TOTAL</span>
                        <span>{pesos(receipt.total)}</span>
                    </p>
                    {receiptBalanceRows(receipt).map((row) => (
                        <ReceiptRow key={row.label} {...row} />
                    ))}
                </div>
            );
        case 'payments':
            return (
                <div className="flex flex-col gap-1.5">
                    <ReceiptRow
                        label="Payment method"
                        value={receiptPaymentMethod(receipt.payments)}
                    />
                    {receiptPaymentRows(receipt.payments).map((row, index) => (
                        <ReceiptRow key={`${row.label}-${index}`} {...row} />
                    ))}
                </div>
            );
        case 'custom_rows':
            return layout.custom_rows.length > 0 ? (
                <div className="flex flex-col gap-0.5">
                    {layout.custom_rows.map((row, index) => (
                        <p key={index} className="text-[11px] wrap-anywhere">
                            {row}
                        </p>
                    ))}
                </div>
            ) : null;
        case 'footer':
            return (
                <p className="text-[10.5px] wrap-anywhere text-neutral-500">
                    {branch.footer || DEFAULT_RECEIPT_FOOTER}
                </p>
            );
        case 'order_qr':
            return layout.order_qr ? (
                <div className="flex flex-col items-center gap-1 pt-1">
                    <img
                        src={layout.order_qr.image}
                        alt="Scan to order again"
                        className="size-28"
                    />
                    <p className="text-[10px] text-neutral-500">
                        Scan to order again
                    </p>
                </div>
            ) : null;
    }
}

function ReceiptRow({
    label,
    value,
    strong = false,
}: {
    label: string;
    value: string;
    strong?: boolean;
}) {
    return (
        <p className="flex w-full items-baseline justify-between gap-3">
            <span className="shrink-0 text-neutral-500">{label}</span>
            <span
                className={`min-w-0 text-right wrap-anywhere ${strong ? 'font-bold' : 'font-semibold'}`}
            >
                {value}
            </span>
        </p>
    );
}
