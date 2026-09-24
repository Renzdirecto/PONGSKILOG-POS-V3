import type {
    RecipeAvailability,
    RecipeSizeAvailability,
} from '@/types/catalog';

/**
 * Display helpers for server-computed Recipe availability. The capacity formula lives only on the server
 * (App\Support\RecipeCapacity); these helpers format its results and build the request for a configuration.
 */

export type CapacitySelection = {
    product_id: string;
    quantity: number;
    modifiers: { group_id: string; option_id: string }[];
};

/** Server answer for the configuration open in a customization dialog. */
export type ConfigurationCapacity = {
    limited: boolean;
    state:
        | 'available'
        | 'out_of_stock'
        | 'recipe_required'
        | 'configuration_error'
        | null;
    /** POS only: servings still possible after the rest of the cart. Customer QR receives `fits` instead. */
    capacity?: number | null;
    fits?: boolean;
    /** Size or Add-on option id → servings (POS) or whether it can be made (Customer QR). */
    options: Record<string, number | boolean>;
};

export const MAX_LINE_QUANTITY = 999;

export function sizeAvailabilityLabel(
    size: Pick<RecipeSizeAvailability, 'state' | 'capacity'>,
): string {
    if (size.state === 'recipe_required') return 'Recipe required';
    if (size.state === 'out_of_stock' || size.capacity === 0)
        return 'Out of stock';

    return size.capacity === null ? 'Available' : `${size.capacity} available`;
}

export function recipeProductLabel(recipe: RecipeAvailability): string {
    if (recipe.state === 'configuration_error') return 'Needs setup';
    if (recipe.state === 'recipe_required') return 'Recipe required';
    if (recipe.state === 'out_of_stock') return 'Out of stock';
    if (recipe.sizes.length === 1 && recipe.capacity !== null)
        return `${recipe.capacity} available`;

    return 'Available';
}

/** The Size availability for a Size option id, or the Regular (no-size) recipe when optionId is null. */
export function sizeAvailability(
    recipe: RecipeAvailability | null | undefined,
    optionId: string | null,
): RecipeSizeAvailability | null {
    return recipe?.sizes.find((size) => size.option_id === optionId) ?? null;
}

/** The rest of the cart (without the line being edited), which shares Branch Ingredient stock with the dialog item. */
export function otherCartLines(
    cart: {
        key: string;
        product: { id: string };
        quantity: number;
        modifiers: CapacitySelection['modifiers'];
    }[],
    editingKey: string | null | undefined,
): CapacitySelection[] {
    return cart
        .filter((line) => line.key !== editingKey)
        .map((line) => ({
            product_id: line.product.id,
            quantity: line.quantity,
            modifiers: line.modifiers,
        }));
}

/** Highest quantity the quantity control may reach for the selected configuration. */
export function quantityCap(capacity: ConfigurationCapacity | null): number {
    if (!capacity?.limited || typeof capacity.capacity !== 'number')
        return MAX_LINE_QUANTITY;

    return Math.max(0, Math.min(MAX_LINE_QUANTITY, capacity.capacity));
}

/** Whether a Size or Add-on option can currently be made with the rest of the selection (unknown = allowed). */
export function optionAvailable(
    capacity: ConfigurationCapacity | null,
    optionId: string,
): boolean {
    const value = capacity?.options[optionId];

    return (
        value === undefined ||
        value === true ||
        (typeof value === 'number' && value > 0)
    );
}

/** A user-facing reason the selected configuration cannot be added, or null when it fits. */
export function configurationProblem(
    capacity: ConfigurationCapacity | null,
    quantity: number,
): string | null {
    if (!capacity?.limited) return null;
    if (capacity.state === 'recipe_required')
        return 'This size needs a recipe before it can be sold.';
    if (capacity.state === 'configuration_error')
        return 'This product needs its Size groups fixed before it can be sold.';
    if (capacity.fits === false)
        return 'Not enough ingredients for this quantity. Reduce the quantity or remove an add-on.';
    if (typeof capacity.capacity === 'number') {
        if (capacity.capacity === 0)
            return 'Out of stock with this selection. Choose another size or remove an add-on.';
        if (quantity > capacity.capacity)
            return `Only ${capacity.capacity} can be made with this selection.`;
    }

    return null;
}

/**
 * How one customization option is shown: Sizes show their availability ("15 available", "Out of stock",
 * "Recipe required"); an Add-on that the current selection cannot fulfil is "Unavailable"; Instructions never are.
 */
export function optionAvailability(
    product: { recipe?: RecipeAvailability | null },
    capacity: ConfigurationCapacity | null,
    optionId: string,
    isSize: boolean,
    isInstruction: boolean,
): { label: string | null; unavailable: boolean } {
    if (isInstruction || !product.recipe)
        return { label: null, unavailable: false };
    if (!isSize) {
        const unavailable = !optionAvailable(capacity, optionId);

        return { label: unavailable ? 'Unavailable' : null, unavailable };
    }
    const size = sizeAvailability(product.recipe, optionId);
    if (size === null) return { label: null, unavailable: false };
    const servings = capacity?.options[optionId];
    if (size.state !== 'available') {
        return { label: sizeAvailabilityLabel(size), unavailable: true };
    }
    if (typeof servings === 'number') {
        return {
            label: sizeAvailabilityLabel({
                state: 'available',
                capacity: servings,
            }),
            unavailable: servings === 0,
        };
    }
    if (servings === false) return { label: 'Out of stock', unavailable: true };

    return { label: sizeAvailabilityLabel(size), unavailable: false };
}
