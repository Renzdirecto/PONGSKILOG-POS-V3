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

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
