export type OperationsPageKey =
    | 'plans'
    | 'overview'
    | 'ingredients'
    | 'recipes'
    | 'stock'
    | 'pamamalengke'
    | 'purchases';

export type OperationsPlan = {
    id: string;
    name: string;
    icon: string;
    description: string | null;
    product_count: number;
    ingredient_count: number;
};

export type OperationsContext = {
    page: OperationsPageKey;
    branch: { id: string; name: string; code: string } | null;
    plans: OperationsPlan[];
    active_plan_id: string | null;
    has_open_store_session: boolean | null;
};

export type RecommendationKind = 'setup' | 'manual' | 'buy' | 'hold' | 'ok';

export type IngredientStatusKey =
    | 'negative'
    | 'setup'
    | 'out'
    | 'buy'
    | 'below'
    | 'above'
    | 'at';

export type OperationsIngredient = {
    id: string;
    name: string;
    icon: string;
    base_unit: string;
    target: string;
    purchase_unit: {
        name: string;
        size: string;
        cost_cents: number | null;
    } | null;
    rule: 'top_up' | 'reorder' | 'none';
    rule_label: string;
    reorder_point: string | null;
    plan_ids: string[];
    locked_unit: boolean;
    archived: boolean;
    updated_at: string | null;
    stock: {
        current: string;
        start: string;
        consumed: string;
        purchased: string;
        wastage: string;
        correction: string;
        updated_at: string | null;
    } | null;
    status: {
        key: IngredientStatusKey;
        label: string;
        tone: 'red' | 'amber' | 'green' | 'neutral' | 'outline';
    } | null;
    recommendation: {
        kind: RecommendationKind;
        units: number;
        base_quantity: string;
        after: string | null;
        estimate_cents: number | null;
        reason: string;
    } | null;
};

export type OperationsFigures = {
    sales_cents: number;
    orders: number;
    items: number;
    uncosted_sales_cents: number;
    cogs_cents: number;
    unknown_cost_lines: number;
    gross_profit_cents: number;
    pamamalengke_cents: number;
    non_stock_cents: number;
    other_expenses_cents: number;
    cash_after_cents: number;
    operating_profit_cents: number;
    incomplete: boolean;
    products: {
        product_id: string;
        name: string;
        quantity: number;
        sales_cents: number;
        state: string;
    }[];
};

export type OperationsSummaryProps = {
    business_date: string;
    plan: OperationsFigures | null;
    plans: Record<string, OperationsFigures>;
    business: OperationsFigures;
    outside_plan_sales_cents: number;
};

export type MarketPlan = {
    available: boolean;
    auto: { ingredient_id: string; skipped: boolean }[];
    setup: string[];
    ok: string[];
    hold: number;
    estimate_cents: number;
    unknown: number;
};

export type ManualEntry = {
    id: string;
    name: string;
    quantity: string;
    unit: string;
    estimated_unit_cost_cents: number | null;
    estimate_cents: number | null;
    note: string | null;
};

export type EarlierPurchase = {
    id: string;
    created_at: string | null;
    items: number;
    bought_by: string | null;
    expense_reference: string;
    estimate_cents: number | null;
    actual_cents: number;
};

export type IngredientMovementGroup = {
    id: string;
    type:
        | 'opening_balance'
        | 'sale_consumption'
        | 'order_edit_adjustment'
        | 'void_restoration'
        | 'purchase_restock'
        | 'wastage'
        | 'count_correction';
    label: string;
    created_at: string;
    plan: { id: string; name: string } | null;
    order: { id: string; number: string | null } | null;
    reason: string | null;
    by: string | null;
    products: string[];
    lines: {
        ingredient_id: string;
        name: string | null;
        unit: string | null;
        delta: string;
        mine: boolean;
    }[];
};

export type RecipeProductSize = {
    key: string;
    option_id: string | null;
    name: string;
    price_cents: number;
    lines: { ingredient_id: string; quantity: string }[] | null;
};

export type RecipeProduct = {
    id: string;
    name: string;
    category: string | null;
    is_active: boolean;
    image_url: string | null;
    no_recipe_needed: boolean;
    tracked_at: string[];
    state: 'set' | 'partial' | 'missing' | 'not_needed';
    sizes: RecipeProductSize[];
};

export type PurchaseRun = {
    id: string;
    created_at: string | null;
    branch: { id: string; code: string; name: string } | null;
    plan: { id: string; name: string } | null;
    bought_by: string | null;
    payment_source: 'cash' | 'cashless' | null;
    expense_id: string;
    expense_reference: string;
    actual_cents: number;
    estimate_cents: number | null;
    estimate_complete: boolean;
    note: string | null;
    items: {
        name: string;
        type: 'ingredient' | 'manual';
        unit: string;
        recommended: string | null;
        quantity: string;
        unit_cost_cents: number;
        total_cents: number;
        base_quantity: string | null;
        base_unit: string | null;
        note: string | null;
    }[];
};
