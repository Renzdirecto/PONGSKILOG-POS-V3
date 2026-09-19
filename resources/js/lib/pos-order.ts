import type { BranchTable, OrderType, PosProduct } from '@/types/pos';

export function orderNumberLabel(orderNumber: string | null): string {
    return orderNumber ? `#${orderNumber}` : 'Preparing order…';
}

export function needsOrderReservation(
    orderType: OrderType | null,
    hasSavedOrder: boolean,
    hasReservation: boolean,
    isSubmitting: boolean,
): boolean {
    return Boolean(
        orderType && !hasSavedOrder && !hasReservation && !isSubmitting,
    );
}

export function customerLabelAfterTableChange(
    tables: BranchTable[],
    currentTableId: string,
    currentCustomerLabel: string,
    nextTableId: string,
): string {
    const nextTable = tables.find((table) => table.id === nextTableId);
    if (nextTable) return nextTable.name;

    const currentTable = tables.find((table) => table.id === currentTableId);

    return currentCustomerLabel === currentTable?.name
        ? ''
        : currentCustomerLabel;
}

export function customerDisplayLabel(
    customerLabel: string | null | undefined,
    tableName: string | null | undefined,
): string {
    return [...new Set([customerLabel, tableName].filter(Boolean))].join(' / ');
}

export function stockAvailabilityLabel(
    product: Pick<
        PosProduct,
        'tracks_inventory' | 'on_hand' | 'stock_status'
    >,
): string {
    if (!product.tracks_inventory) return 'Available';
    if (product.on_hand === 0) return 'Out of stock · 0 left';
    if (product.stock_status === 'low_stock')
        return `Low stock · ${product.on_hand} left`;

    return `In stock · ${product.on_hand} left`;
}
