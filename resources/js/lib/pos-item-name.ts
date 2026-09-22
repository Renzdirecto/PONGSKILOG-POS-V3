import type { CartLine, OrderSummary } from '@/types/pos';

export type OperationalItemName = {
    name: string;
    sizePrefix: string | null;
    displayName: string;
};

export function cartItemName(line: CartLine): OperationalItemName {
    const sizeGroup = line.product.modifier_groups?.find(
        (group) => group.semantic_role === 'size',
    );
    const sizePrefix = sizeGroup?.options.find((option) =>
        line.modifiers.some(
            (selection) =>
                selection.group_id === sizeGroup.id &&
                selection.option_id === option.id,
        ),
    )?.name;

    return {
        name: line.product.name,
        sizePrefix: sizePrefix ?? null,
        displayName: sizePrefix
            ? `${sizePrefix} ${line.product.name}`
            : line.product.name,
    };
}

export function savedItemName(
    item: OrderSummary['items'][number],
): OperationalItemName {
    return {
        name: item.name,
        sizePrefix: item.size_prefix ?? null,
        displayName: item.display_name ?? item.name,
    };
}
