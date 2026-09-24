import type { StockStatus } from './inventory';

export type CatalogChoice = { id: string; name: string; is_active: boolean };
export type CategoryIconKey =
    | 'utensils'
    | 'meal'
    | 'rice'
    | 'drink'
    | 'coffee'
    | 'dessert'
    | 'snack'
    | 'chicken'
    | 'breakfast'
    | 'add_ons'
    | 'food';
export type ModifierSemanticRole = 'size' | 'instruction' | null;
/** Server-computed servings of one Size (Regular when option_id is null); Customer QR never receives counts. */
export type RecipeSizeAvailability = {
    key: string;
    option_id: string | null;
    name: string;
    state: 'available' | 'out_of_stock' | 'recipe_required';
    capacity: number | null;
};
/** Recipe-based availability of a Recipe-backed Product; null for direct resale or Products without a recipe. */
export type RecipeAvailability = {
    state:
        | 'available'
        | 'out_of_stock'
        | 'recipe_required'
        | 'configuration_error';
    capacity: number | null;
    sizes: RecipeSizeAvailability[];
};
export type CashierCatalog = {
    categories: { id: string; name: string; icon_key?: CategoryIconKey }[];
    products: {
        id: string;
        name: string;
        description?: string | null;
        category_id: string;
        category_name: string;
        effective_price: string;
        is_available: boolean;
        availability_reason?:
            | 'product_disabled'
            | 'category_disabled'
            | 'branch_unavailable'
            | 'out_of_stock'
            | 'recipe_required'
            | null;
        stock_status: StockStatus;
        tracks_inventory: boolean;
        on_hand: number | null;
        recipe?: RecipeAvailability | null;
        image_url: string | null;
        has_modifiers: boolean;
        modifier_groups?: {
            id: string;
            name: string;
            semantic_role?: ModifierSemanticRole;
            selection_type: 'single' | 'multiple';
            min_select: number;
            max_select: number;
            options: {
                id: string;
                name: string;
                price_delta: string;
                sort_order: number;
            }[];
        }[];
    }[];
};
export type Category = CatalogChoice & {
    icon_key: CategoryIconKey | null;
    sort_order: number;
    products_count: number;
};
export type ModifierOption = CatalogChoice & {
    modifier_group_id: string;
    price_delta: string;
    sort_order: number;
};
export type ModifierGroup = CatalogChoice & {
    semantic_role: ModifierSemanticRole;
    selection_type: 'single' | 'multiple';
    min_select: number;
    max_select: number;
    options: ModifierOption[];
    product_ids?: string[];
};
export type BranchConfiguration = Pick<
    BranchPrice,
    'branch_id' | 'code' | 'name'
>;
export type BranchPrice = {
    branch_id: string;
    code: string;
    name: string;
    price_override: string | null;
    effective_price: string;
    is_available: boolean;
    tracks_inventory: boolean;
    low_stock_threshold: number | null;
    effective_available: boolean;
};
export type CatalogProduct = CatalogChoice & {
    description: string | null;
    category_id: string;
    category_name: string;
    category_active: boolean;
    default_price: string;
    image_url: string | null;
    has_image: boolean;
    modifier_group_ids: string[];
    modifier_group_count: number;
    inventory: {
        tracked: boolean;
        on_hand: number | null;
        low_stock_threshold: number | null;
        status: StockStatus;
    } | null;
    branch_prices: BranchPrice[];
};
