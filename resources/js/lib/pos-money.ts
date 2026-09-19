import type { CartLine } from '@/types/pos';

export function cents(value: string): bigint {
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

export function pesos(value: bigint | string): string {
    const amount = typeof value === 'string' ? cents(value) : value;
    return `₱${(amount / 100n).toLocaleString('en-PH')}.${(amount % 100n).toString().padStart(2, '0')}`;
}

export function exactCash(total: bigint, cashless: bigint): string {
    const due = total > cashless ? total - cashless : 0n;

    return `${due / 100n}.${String(due % 100n).padStart(2, '0')}`;
}

export function paymentTotals(total: bigint, cash: bigint, cashless: bigint) {
    const received = cash + cashless;

    return {
        received,
        remaining: total > received ? total - received : 0n,
        change: received > total ? received - total : 0n,
    };
}

export function selectedOptions(line: CartLine) {
    return (line.product.modifier_groups ?? []).flatMap((group) =>
        group.options.filter((option) =>
            line.modifiers.some(
                (selected) =>
                    selected.group_id === group.id &&
                    selected.option_id === option.id,
            ),
        ),
    );
}

export function lineCents(line: CartLine): bigint {
    return (
        selectedOptions(line).reduce(
            (total, option) => total + cents(option.price_delta),
            cents(line.product.effective_price),
        ) * BigInt(line.quantity)
    );
}

export function validPayment(
    total: bigint,
    method: 'cash' | 'cashless' | 'split',
    cash: string,
    cashless: string,
): boolean {
    const money = /^\d{1,12}(?:\.\d{1,2})?$/;
    if (method === 'cashless') return true;
    if (!money.test(cash)) return false;
    if (method === 'cash') return cents(cash) >= total;
    if (!money.test(cashless)) return false;
    const portion = cents(cashless);
    return portion > 0n && portion < total && cents(cash) >= total - portion;
}
