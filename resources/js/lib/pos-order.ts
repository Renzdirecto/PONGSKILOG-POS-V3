import type { PosProduct } from '@/types/pos';

export function orderNumberLabel(orderNumber: string | null): string {
    return orderNumber ? `#${orderNumber}` : 'Preparing order…';
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
