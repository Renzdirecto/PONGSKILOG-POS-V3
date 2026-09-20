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
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
