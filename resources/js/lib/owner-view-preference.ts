export type OwnerViewMode = 'tile' | 'list';

export function restoredOwnerViewMode(
    storedValue: string | null,
): OwnerViewMode {
    return storedValue === 'list' ? 'list' : 'tile';
}
