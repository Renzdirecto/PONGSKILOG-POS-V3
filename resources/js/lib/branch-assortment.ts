import type { BranchSummary } from '@/types';

/** The Products page scope: Branch-scoped accounts manage only their selected Branch's assortment and configuration. */
export type ProductScope = {
    mode: 'branch' | 'business';
    can_edit_definitions: boolean;
    branch: BranchSummary | null;
    copy_sources: BranchSummary[];
};

/** A canonical Product that the selected Branch does not sell (removed from its assortment). */
export type AssortmentCandidate = {
    id: string;
    name: string;
    category_name: string;
    default_price: string;
    is_active: boolean;
};

/** One review row of "Copy products from another Branch" (server-authorized source and destination). */
export type CopyPreviewRow = {
    product_id: string;
    name: string;
    category_name: string;
    is_active: boolean;
    source: {
        configured: boolean;
        sold: boolean;
        price_override: string | null;
        effective_price: string;
        tracks_inventory: boolean;
        low_stock_threshold: number | null;
    };
    destination: {
        configured: boolean;
        sold: boolean;
        effective_price: string;
    };
};

export type CopyPreview = {
    source: BranchSummary;
    destination: BranchSummary;
    products: CopyPreviewRow[];
};

/**
 * What confirming the copy will do for the selected Products. Products already configured at the destination are kept
 * (skipped) unless overwrite was explicitly chosen; stock is never part of a copy.
 */
export function copySummary(
    rows: readonly CopyPreviewRow[],
    selectedIds: ReadonlySet<string>,
    overwrite: boolean,
): { copy: number; overwrite: number; skip: number; tracked: number } {
    const selected = rows.filter((row) => selectedIds.has(row.product_id));
    const existing = selected.filter((row) => row.destination.configured);
    const applied = overwrite
        ? selected
        : selected.filter((row) => !row.destination.configured);

    return {
        copy: selected.length - existing.length,
        overwrite: overwrite ? existing.length : 0,
        skip: overwrite ? 0 : existing.length,
        tracked: applied.filter((row) => row.source.tracks_inventory).length,
    };
}

/** The safe default selection: Products the destination has not configured yet. */
export function newAtDestination(rows: readonly CopyPreviewRow[]): string[] {
    return rows
        .filter((row) => !row.destination.configured)
        .map((row) => row.product_id);
}

/** Case-insensitive name or category search for the bulk pickers. */
export function matchesSearch(
    row: { name: string; category_name: string },
    search: string,
): boolean {
    const term = search.trim().toLowerCase();

    return (
        term === '' ||
        row.name.toLowerCase().includes(term) ||
        row.category_name.toLowerCase().includes(term)
    );
}
