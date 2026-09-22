export const POS_CATALOG_REALTIME_EVENTS = [
    '.inventory.changed',
    '.product.availability_changed',
    '.product.branch_configuration_changed',
] as const;

export type PosCatalogRealtimeEvent = {
    event_id: string;
    event_type:
        | 'inventory.changed'
        | 'product.availability_changed'
        | 'product.branch_configuration_changed';
    branch_id: string;
    product_id: string;
    occurred_at: string;
    version: number;
};

export function shouldRefetchCatalogAfterConnectionChange(
    previous: string,
    current: string,
    hasConnected: boolean,
): boolean {
    return hasConnected && previous !== 'connected' && current === 'connected';
}
