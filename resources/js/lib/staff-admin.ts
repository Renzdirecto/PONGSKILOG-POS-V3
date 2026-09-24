export type StaffRoleOption = {
    name: string;
    label: string;
    business_wide: boolean;
};

type StaffState = {
    role: string;
    is_active: boolean;
    branch_ids: string[];
};

const BUSINESS_WIDE_ROLES = ['owner', 'super_admin'];

/**
 * Plain-language confirmations for high-impact Staff edits: any role change (custom access resets), a change into or
 * out of Owner/Super Admin, deactivation, and removed Branch access. The server enforces the rules; this only makes
 * the consequence explicit before saving.
 */
export function staffChangeWarnings({
    member,
    next,
    roles,
    branchNames,
}: {
    member: StaffState & {
        custom_access_count: number;
        business_wide: boolean;
    };
    next: StaffState;
    roles: readonly StaffRoleOption[];
    branchNames: Record<string, string>;
}): string[] {
    const warnings: string[] = [];
    const label = (name: string) =>
        roles.find((role) => role.name === name)?.label ?? 'No role';

    if (member.role !== next.role && next.role !== '') {
        const elevated =
            BUSINESS_WIDE_ROLES.includes(next.role) ||
            BUSINESS_WIDE_ROLES.includes(member.role);
        warnings.push(
            `Role changes from ${label(member.role)} to ${label(next.role)}.` +
                (next.role === 'super_admin'
                    ? ' A Super Admin has full access to every workspace and the Control Center.'
                    : elevated
                      ? ' This changes business-wide access.'
                      : ''),
        );
        if (member.custom_access_count > 0) {
            warnings.push(
                `${member.custom_access_count} custom permission${member.custom_access_count === 1 ? '' : 's'} will be removed; the account follows the new role baseline.`,
            );
        }
        if (BUSINESS_WIDE_ROLES.includes(next.role) && !member.business_wide) {
            warnings.push(
                'Branch assignments are cleared (business-wide role).',
            );
        }
    }

    if (member.is_active && !next.is_active) {
        warnings.push(
            'The account is deactivated: it cannot sign in and open sessions are signed out.',
        );
    }

    const removed = member.business_wide
        ? []
        : member.branch_ids.filter((id) => !next.branch_ids.includes(id));
    if (removed.length > 0 && !BUSINESS_WIDE_ROLES.includes(next.role)) {
        warnings.push(
            `Branch access removed: ${removed.map((id) => branchNames[id] ?? 'a Branch').join(', ')}.`,
        );
    }

    return warnings;
}
