/** Permission metadata comes from the server's PermissionCatalog; nothing here re-declares permission names. */
export type PermissionMeta = {
    key: string;
    label: string;
    description: string;
    category: string;
    scope: 'branch' | 'business' | 'either';
    super_admin_only: boolean;
};

export type OverrideEffect = 'inherit' | 'allow' | 'deny';

export type EffectiveState = 'role' | 'custom' | 'none' | 'removed';

export const EFFECTIVE_STATE_LABELS: Record<EffectiveState, string> = {
    role: 'Included by role',
    custom: 'Custom access',
    none: 'No access',
    removed: 'No access · removed',
};

export const OVERRIDE_LABELS: Record<OverrideEffect, string> = {
    inherit: 'Inherit',
    allow: 'Allow',
    deny: 'Deny',
};

/**
 * The effective result of one permission for an account: an explicit ALLOW or DENY wins, otherwise the Role baseline.
 * This mirrors EffectivePermissions on the server and is only used to preview unsaved choices.
 */
export function effectiveState(
    includedByRole: boolean,
    override: OverrideEffect,
): EffectiveState {
    if (override === 'allow') {
        return includedByRole ? 'role' : 'custom';
    }
    if (override === 'deny') {
        return includedByRole ? 'removed' : 'none';
    }

    return includedByRole ? 'role' : 'none';
}

/** ALLOW of an included permission, or DENY of an excluded one, would equal the Role default and is not stored. */
export function isRedundantOverride(
    includedByRole: boolean,
    effect: OverrideEffect,
): boolean {
    return (
        (effect === 'allow' && includedByRole) ||
        (effect === 'deny' && !includedByRole)
    );
}

/** Groups catalog permissions by category, keeping the catalog order within each category. */
export function groupPermissions(
    permissions: readonly PermissionMeta[],
    categories: Record<string, string>,
): { category: string; label: string; permissions: PermissionMeta[] }[] {
    return Object.entries(categories)
        .map(([category, label]) => ({
            category,
            label,
            permissions: permissions.filter(
                (permission) => permission.category === category,
            ),
        }))
        .filter((group) => group.permissions.length > 0);
}

export function permissionDiff(
    before: readonly string[],
    after: readonly string[],
): { added: string[]; removed: string[] } {
    return {
        added: after.filter((permission) => !before.includes(permission)),
        removed: before.filter((permission) => !after.includes(permission)),
    };
}

/** The non-inherit overrides to submit, dropping choices that equal the Role default. */
export function meaningfulOverrides(
    choices: Record<string, OverrideEffect>,
    baseline: readonly string[],
): Record<string, OverrideEffect> {
    return Object.fromEntries(
        Object.entries(choices).filter(
            ([permission, effect]) =>
                effect !== 'inherit' &&
                !isRedundantOverride(baseline.includes(permission), effect),
        ),
    );
}

export function sameOverrides(
    a: Record<string, OverrideEffect>,
    b: Record<string, OverrideEffect>,
): boolean {
    const keys = new Set([...Object.keys(a), ...Object.keys(b)]);

    return [...keys].every(
        (key) => (a[key] ?? 'inherit') === (b[key] ?? 'inherit'),
    );
}
