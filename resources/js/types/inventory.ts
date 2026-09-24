export type StockStatus =
    | 'not_tracked'
    | 'in_stock'
    | 'low_stock'
    | 'out_of_stock';

export type InventoryProduct = {
    id: string;
    name: string;
    category_name: string;
    image_url: string | null;
    tracked: boolean;
    on_hand: number | null;
    low_stock_threshold: number | null;
    status: StockStatus;
    last_updated_at: string | null;
};

export type InventoryMovement = {
    id: string;
    created_at: string;
    movement_type: string;
    movement_label: string;
    quantity_delta: number;
    reason: string | null;
    created_by_name: string | null;
};

export type InventoryPagination<T> = {
    data: T[];
    total: number;
    current_page: number;
    last_page: number;
};

export type InventoryFilters = {
    type?: 'all' | 'products' | 'ingredients';
    search?: string;
    stock_status?: StockStatus | 'all';
    category?: string;
};

export type InventorySummary = Record<StockStatus, number>;
