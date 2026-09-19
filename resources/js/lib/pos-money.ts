import type { CartLine } from '@/types/pos';

export function cents(value: string): bigint {
    const [whole, fraction = ''] = value.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}

export function pesos(value: bigint | string): string {
    const amount = typeof value === 'string' ? cents(value) : value;
    return `₱${(amount / 100n).toLocaleString('en-PH')}.${(amount % 100n).toString().padStart(2, '0')}`;
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
