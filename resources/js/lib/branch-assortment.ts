import type { BranchSummary } from '@/types';

/** The Products page scope: Branch-scoped accounts manage only their selected Branch's assortment and configuration. */
export type ProductScope = {
    mode: 'branch' | 'business';
    can_edit_definitions: boolean;
    branch: BranchSummary | null;
    copy_sources: BranchSummary[];
    /** Copying Products may also bring their Operations setup, which needs Operations access. */
    can_copy_operations: boolean;
};

/** A global Product that is not in the selected Branch's assortment (never added, removed, or sold nowhere yet). */
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

/** The source's Operations setup per Product, with each Plan and Ingredient it brings and its destination state. */
export type CopyOperationsDetails = {
    products: Record<
        string,
        {
            mode: 'recipe' | 'direct' | 'product_stock' | 'none';
            plan_id: string | null;
            recipes: number;
            effects: number;
            ingredient_ids: string[];
            destination_configured: boolean;
        }
    >;
    plans: Record<string, { name: string; exists: boolean }>;
    ingredients: Record<
        string,
        { name: string; exists: boolean; conflict: boolean }
    >;
};

export type CopyPreview = {
    source: BranchSummary;
    destination: BranchSummary;
    products: CopyPreviewRow[];
    /** Null when the viewer has no Operations access (Operations setup cannot be copied then). */
    operations: CopyOperationsDetails | null;
};

/**
 * The server's outcome of a confirmed copy (flashed as `assortmentCopy`). The copy is partially successful by design: a
 * Product whose destination setup conflicts is skipped alone with its reason, the others are still copied.
 */
export type AssortmentCopyResult = {
    copied: number;
    overwritten: number;
    kept: number;
    conflicts: { product_id: string; name: string; reason: string }[];
    operations_skipped: string[];
};

/** A result with skipped Products or skipped Operations setup stays on screen so every reason can be read. */
export function copyResultNeedsReview(
    result: AssortmentCopyResult | null | undefined,
): result is AssortmentCopyResult {
    return (
        result != null &&
        (result.conflicts.length > 0 || result.operations_skipped.length > 0)
    );
}

/**
 * What "Copy Operations setup for selected Products" brings to the destination, deduplicated across the selection:
 * Plans and Ingredients already there are reused (kept), recipe setup already there is kept unless overwrite was
 * chosen. Stock and history are never part of it.
 */
export function operationsCopySummary(
    details: CopyOperationsDetails,
    selectedIds: ReadonlySet<string>,
    overwrite: boolean,
): {
    plans: number;
    plansKept: number;
    ingredients: number;
    ingredientsKept: number;
    conflicts: number;
    recipes: number;
    effects: number;
    direct: number;
    configuredKept: number;
} {
    const plans = new Set<string>();
    const ingredients = new Set<string>();
    let recipes = 0;
    let effects = 0;
    let direct = 0;
    let configuredKept = 0;
    for (const id of selectedIds) {
        const product = details.products[id];
        if (!product) {
            continue;
        }
        if (product.plan_id !== null) {
            plans.add(product.plan_id);
        }
        product.ingredient_ids.forEach((ingredient) =>
            ingredients.add(ingredient),
        );
        if (product.mode !== 'recipe' && product.mode !== 'direct') {
            continue;
        }
        if (product.destination_configured && !overwrite) {
            configuredKept++;
            continue;
        }
        recipes += product.recipes;
        effects += product.effects;
        direct += product.mode === 'direct' ? 1 : 0;
    }
    const planRows = [...plans].map((id) => details.plans[id]).filter(Boolean);
    const ingredientRows = [...ingredients]
        .map((id) => details.ingredients[id])
        .filter(Boolean);

    return {
        plans: planRows.filter((plan) => overwrite || !plan.exists).length,
        plansKept: overwrite
            ? 0
            : planRows.filter((plan) => plan.exists).length,
        ingredients: ingredientRows.filter(
            (row) => !row.exists || (overwrite && !row.conflict),
        ).length,
        ingredientsKept: overwrite
            ? 0
            : ingredientRows.filter((row) => row.exists && !row.conflict)
                  .length,
        conflicts: ingredientRows.filter((row) => row.conflict).length,
        recipes,
        effects,
        direct,
        configuredKept,
    };
}

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
