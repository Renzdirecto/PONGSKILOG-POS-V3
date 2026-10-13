const requiredProps: Record<string, readonly string[]> = {
    'workspaces/show': ['catalog', 'store'],
    'workspaces/kitchen': ['kitchenBoard'],
    'workspaces/transaction-history': ['transactions', 'metrics', 'filters'],
    'workspaces/reports': ['report', 'analytics', 'kitchenNow', 'filters'],
    'workspaces/cashier-dashboard': ['dashboard'],
    'workspaces/owner-dashboard': ['analytics', 'report'],
    'workspaces/website-analytics': ['report'],
};

export function workspaceIsLoading(
    component: string,
    props: Record<string, unknown>,
): boolean {
    return (requiredProps[component] ?? []).some(
        (key) => props[key] === undefined,
    );
}

/** Inertia replaces instant pageProps; explicitly retain its shared authentication and Branch context. */
export function instantWorkspaceProps(
    shared: Record<string, unknown>,
    target: Record<string, unknown> = {},
): Record<string, unknown> {
    return { ...shared, ...target };
}
