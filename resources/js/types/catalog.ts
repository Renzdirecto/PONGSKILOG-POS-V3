import type { StockStatus } from './inventory';

export type CatalogChoice = { id: string; name: string; is_active: boolean };
export type CashierCatalog = {
    categories: { id: string; name: string }[];
    products: {
        id: string;
        name: string;
        category_id: string;
        category_name: string;
        effective_price: string;
        is_available: boolean;
        stock_status: StockStatus;
        image_url: string | null;
        has_modifiers: boolean;
        modifier_groups?: {
            id: string;
            name: string;
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
    sort_order: number;
    products_count: number;
};
export type ModifierOption = CatalogChoice & {
    modifier_group_id: string;
    price_delta: string;
    sort_order: number;
};
export type ModifierGroup = CatalogChoice & {
    selection_type: 'single' | 'multiple';
    min_select: number;
    max_select: number;
    options: ModifierOption[];
};
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
    branch_prices: BranchPrice[];
};
