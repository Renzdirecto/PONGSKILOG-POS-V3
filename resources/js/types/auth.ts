export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
};

export type Auth = {
    user: User | null;
    roles: string[];
    permissions: string[];
};

export type BranchSummary = {
    id: string;
    name: string;
    code: string;
};

export type BranchContext = {
    current: BranchSummary | null;
    businessWide: boolean;
    selectableBranches: BranchSummary[];
};

export type StoreContext = {
    status: 'open' | 'closed' | null;
    isOpen: boolean;
    branchId: string | null;
};

export type CurrentStoreSession = {
    id: string;
    opened_at: string;
    opening_cash_amount: string;
    opening_cashless_amount: string;
    opened_by: {
        name: string;
    };
    branch: BranchSummary;
    expense_totals: {
        cash: string;
        cashless: string;
        total: string;
    };
    expenses: StoreSessionExpense[];
    expense_count: number;
    expenses_truncated: boolean;
    restock_products: {
        id: string;
        name: string;
        on_hand: number;
    }[];
    inventory_adjustments: StoreSessionInventoryAdjustment[];
    inventory_adjustment_count: number;
};

export type StoreSessionInventoryAdjustment = {
    id: string;
    product_name: string;
    quantity: number;
    reason_code: string;
    reason_label: string;
    note: string | null;
    created_at: string;
    created_by: { name: string };
};

export type StoreSessionExpense = {
    id: string;
    description: string;
    amount: string;
    payment_source: 'cash' | 'cashless';
    note: string | null;
    created_at: string;
    created_by: { name: string };
    item: {
        product_id: string;
        product_name: string;
        quantity: number;
        movement_id: string | null;
    } | null;
    receipt: { name: string; url: string } | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
