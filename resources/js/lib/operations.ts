/**
 * Display helpers for Owner Operations. Every authoritative figure (stock, recommendations, COGS, profit) comes from
 * the server; these helpers only format it, or compute display-only previews with exact integer math (never floats
 * for money or quantities): quantities in ten-thousandths of a base unit, money in centavos.
 */

export const QUANTITY_SCALE = 10_000;

const QUANTITY_PATTERN = /^\d{1,7}(?:\.\d{1,4})?$/;
const MONEY_PATTERN = /^\d{1,9}(?:\.\d{1,2})?$/;

/** "29.5" → 295000; null when the text is not a valid non-negative quantity with at most four decimals. */
export function parseQuantity(value: string): number | null {
    const text = value.trim();
    if (!QUANTITY_PATTERN.test(text)) {
        return null;
    }
    const [whole, fraction = ''] = text.split('.');

    return Number(whole) * QUANTITY_SCALE + Number(fraction.padEnd(4, '0'));
}

/** Signed server display quantity such as "-0.25" → -2500. */
export function parseSignedQuantity(value: string): number {
    const negative = value.startsWith('-');
    const scaled = parseQuantity(negative ? value.slice(1) : value) ?? 0;

    return negative ? -scaled : scaled;
}

/** 295000 → "29.5". */
export function formatScaled(scaled: number): string {
    const sign = scaled < 0 ? '-' : '';
    const magnitude = Math.abs(scaled);
    const whole = Math.trunc(magnitude / QUANTITY_SCALE);
    const fraction = String(magnitude % QUANTITY_SCALE)
        .padStart(4, '0')
        .replace(/0+$/, '');

    return `${sign}${whole.toLocaleString('en-PH')}${fraction ? `.${fraction}` : ''}`;
}

/** "12.50" → 1250; null when blank or invalid. */
export function parseMoney(value: string): number | null {
    const text = value.trim();
    if (!MONEY_PATTERN.test(text)) {
        return null;
    }
    const [whole, fraction = ''] = text.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

/** Plural-aware unit label, matching the server (pc → pcs, pack → packs, ml/L/g/kg never change). */
export function unitLabel(unit: string, quantity: number): string {
    if (
        ['ml', 'L', 'g', 'kg'].includes(unit) ||
        (quantity > 0 && quantity <= 1)
    ) {
        return unit;
    }
    if (unit === 'pc') {
        return 'pcs';
    }

    return /(ch|sh|x|s)$/.test(unit) ? `${unit}es` : `${unit}s`;
}

/** Server display quantity plus unit: ("29.5", "pc") → "29.5 pcs", ("1", "pack") → "1 pack". */
export function formatQuantity(display: string, unit: string): string {
    const scaled = parseSignedQuantity(display);
    const shown = formatScaled(scaled);

    return `${shown} ${unitLabel(unit, Math.abs(scaled) / QUANTITY_SCALE)}`;
}

/** Signed movement quantity: "-0.5" → "−0.5", "30" → "+30". */
export function formatDelta(display: string): string {
    const scaled = parseSignedQuantity(display);
    if (scaled === 0) {
        return '0';
    }

    return `${scaled < 0 ? '−' : '+'}${formatScaled(Math.abs(scaled))}`;
}

export function formatPeso(cents: number, wholeOnly = false): string {
    const sign = cents < 0 ? '−' : '';
    const magnitude = Math.abs(cents);
    const whole = Math.trunc(magnitude / 100).toLocaleString('en-PH');

    return wholeOnly && magnitude % 100 === 0
        ? `${sign}₱${whole}`
        : `${sign}₱${whole}.${String(magnitude % 100).padStart(2, '0')}`;
}

/** Rounded half away from zero: numerator ÷ denominator for non-negative integers. */
function divideRounded(numerator: number, denominator: number): number {
    const quotient = Math.trunc(numerator / denominator);

    return (numerator % denominator) * 2 >= denominator
        ? quotient + 1
        : quotient;
}

/**
 * Display-only estimate of a recipe line: quantity × purchase-unit cost ÷ purchase-unit size, in centavos. The saved
 * estimate of every sale is snapshotted on the server; this only previews an unsaved draft.
 */
export function estimateLineCents(
    quantity: string,
    purchaseUnit: { size: string; cost_cents: number | null } | null,
): number | null {
    const scaled = parseQuantity(quantity);
    const size = purchaseUnit ? parseQuantity(purchaseUnit.size) : null;
    if (
        scaled === null ||
        size === null ||
        size <= 0 ||
        purchaseUnit?.cost_cents == null
    ) {
        return null;
    }

    return divideRounded(scaled * purchaseUnit.cost_cents, size);
}

/** Actual line total for a decimal quantity at a unit price, rounded to the centavo (matches the server). */
export function lineTotalCents(
    quantity: string,
    unitCost: string,
): number | null {
    const scaled = parseQuantity(quantity);
    const cents = parseMoney(unitCost);
    if (scaled === null || cents === null) {
        return null;
    }

    return divideRounded(scaled * cents, QUANTITY_SCALE);
}

/**
 * Profit divider: a display-only calculator. Splits centavos into equal shares; any centavos that cannot be split
 * equally are reported, never invented. It records nothing.
 */
export function divideProfit(
    cents: number,
    shares: number,
): { each: number; remainder: number; shares: number } {
    const count = Math.max(1, Math.min(20, Math.trunc(shares) || 1));
    const each = Math.trunc(cents / count);

    return { each, remainder: cents - each * count, shares: count };
}

export function planQuery(planId: string | null | undefined): {
    query?: { plan: string };
} {
    return planId ? { query: { plan: planId } } : {};
}

/** Share of target for a stock bar (0–100), never negative. */
export function stockPercent(current: string, target: string): number {
    const targetScaled = parseSignedQuantity(target);
    if (targetScaled <= 0) {
        return 0;
    }

    return Math.max(
        0,
        Math.min(100, (parseSignedQuantity(current) / targetScaled) * 100),
    );
}

export type ChecklistLine = {
    bought: boolean;
    unavailable: boolean;
    quantity: string;
    unitCost: string;
    note: string;
    open: boolean;
};

export type ChecklistState = {
    lines: Record<string, ChecklistLine>;
    paymentSource: 'cash' | 'cashless';
    idempotencyKey: string | null;
};

export const emptyChecklist = (): ChecklistState => ({
    lines: {},
    paymentSource: 'cash',
    idempotencyKey: null,
});

export function checklistStorageKey(branchId: string, planId: string): string {
    return `pongskilog.pamamalengke.v1.${branchId}.${planId}`;
}

/** Per-device checklist progress. Storage may be missing or blocked; the page then simply starts empty. */
export function readChecklist(key: string): ChecklistState {
    try {
        const raw = window.localStorage.getItem(key);
        if (!raw) {
            return emptyChecklist();
        }
        const parsed = JSON.parse(raw) as Partial<ChecklistState>;

        return {
            lines:
                parsed.lines && typeof parsed.lines === 'object'
                    ? parsed.lines
                    : {},
            paymentSource:
                parsed.paymentSource === 'cashless' ? 'cashless' : 'cash',
            idempotencyKey:
                typeof parsed.idempotencyKey === 'string'
                    ? parsed.idempotencyKey
                    : null,
        };
    } catch {
        return emptyChecklist();
    }
}

export function writeChecklist(key: string, state: ChecklistState): void {
    try {
        window.localStorage.setItem(key, JSON.stringify(state));
    } catch {
        /** Storage is a convenience only; the purchase itself is saved on the server. */
    }
}

export function clearChecklist(key: string): void {
    try {
        window.localStorage.removeItem(key);
    } catch {
        /** Nothing to clear when storage is unavailable. */
    }
}

export type ChecklistItem = {
    key: string;
    type: 'ingredient' | 'manual';
    ingredientId: string | null;
    entryId: string | null;
    name: string;
    unit: string;
    planned: string;
    estimatedUnitCents: number | null;
};

/** Bought lines ready for Confirm, with their exact actual totals (null when the cost is missing or invalid). */
export function boughtLines(
    items: ChecklistItem[],
    state: ChecklistState,
): (ChecklistItem & {
    line: ChecklistLine;
    totalCents: number | null;
    estimateCents: number | null;
})[] {
    return items
        .map((item) => {
            const line = state.lines[item.key] ?? defaultLine(item);

            return {
                ...item,
                line,
                totalCents: lineTotalCents(line.quantity, line.unitCost),
                estimateCents:
                    item.estimatedUnitCents === null
                        ? null
                        : lineTotalCents(
                              item.planned,
                              (item.estimatedUnitCents / 100).toFixed(2),
                          ),
            };
        })
        .filter(
            (item) =>
                item.line.bought &&
                !item.line.unavailable &&
                (parseQuantity(item.line.quantity) ?? 0) > 0,
        );
}

export function defaultLine(item: ChecklistItem): ChecklistLine {
    return {
        bought: false,
        unavailable: false,
        quantity: item.planned,
        unitCost:
            item.estimatedUnitCents === null
                ? ''
                : (item.estimatedUnitCents / 100).toFixed(2),
        note: '',
        open: false,
    };
}

/** What the Recipes page shows for a Product (and its selected Size): the inventory mode decides before recipes do. */
export type RecipeSetupState =
    | 'product_stock'
    | 'no_recipe_needed'
    | 'configuration_error'
    | 'recipe_missing'
    | 'recipe_set';

export function recipeSetupState(
    product: {
        inventory_mode: 'product_stock' | 'no_recipe_needed' | 'recipe';
        size_conflict: string[] | null;
    },
    size: { lines: unknown[] | null } | undefined,
): RecipeSetupState {
    if (product.inventory_mode === 'product_stock') return 'product_stock';
    if (product.inventory_mode === 'no_recipe_needed')
        return 'no_recipe_needed';
    if (product.size_conflict !== null) return 'configuration_error';

    return size?.lines?.length ? 'recipe_set' : 'recipe_missing';
}

/** Product list note on the Recipes page, e.g. "2 of 3 sizes set" or "Uses Product stock". */
export function recipeNavNote(product: {
    state:
        | 'set'
        | 'partial'
        | 'missing'
        | 'not_needed'
        | 'product_stock'
        | 'configuration_error';
    sizes: { lines: unknown[] | null }[];
}): string {
    switch (product.state) {
        case 'product_stock':
            return 'Uses Product stock';
        case 'not_needed':
            return 'No recipe needed';
        case 'configuration_error':
            return 'Size groups need fixing';
        case 'missing':
            return 'Recipe required';
        default:
            return product.sizes.length === 1
                ? 'Recipe set'
                : `${product.sizes.filter((size) => size.lines?.length).length} of ${product.sizes.length} sizes set`;
    }
}

/** "Yakult 1 pc, Nata 30 g" for an Add-on effect, or "No ingredient effect". */
export function effectSummary(
    lines: { ingredient_id: string; quantity: string }[] | null,
    ingredients: Map<string, { name: string; base_unit: string }>,
): string {
    if (!lines?.length) return 'No ingredient effect';

    return lines
        .map((line) => {
            const ingredient = ingredients.get(line.ingredient_id);

            return `${ingredient?.name ?? 'Ingredient'} +${formatQuantity(line.quantity, ingredient?.base_unit ?? '')}`;
        })
        .join(', ');
}
