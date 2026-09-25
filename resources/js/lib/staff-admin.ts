export type StaffRoleOption = {
    name: string;
    label: string;
    business_wide: boolean;
    /** A Custom Role created in Access Control (Super Admin only), not one of the five System roles. */
    custom?: boolean;
};

type StaffState = {
    role: string;
    is_active: boolean;
    branch_ids: string[];
};

/**
 * System and Custom Role options for a Staff form, grouped so Custom Roles never look like built-in roles.
 */
export function groupStaffRoles(roles: readonly StaffRoleOption[]): {
    system: StaffRoleOption[];
    custom: StaffRoleOption[];
} {
    return {
        system: roles.filter((role) => !role.custom),
        custom: roles.filter((role) => role.custom),
    };
}

/**
 * Plain-language confirmations for high-impact Staff edits: any role change (custom access resets), a change into or
 * out of a business-wide role (Owner, Super Admin or a business-wide Custom Role), deactivation, and removed Branch
 * access. The server enforces the rules; this only makes the consequence explicit before saving.
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
    const option = (name: string) => roles.find((role) => role.name === name);
    const label = (name: string) => option(name)?.label ?? 'No role';
    const nextBusinessWide = option(next.role)?.business_wide ?? false;

    if (member.role !== next.role && next.role !== '') {
        const elevated = nextBusinessWide || member.business_wide;
        warnings.push(
            `Role changes from ${label(member.role)} to ${label(next.role)}.` +
                (next.role === 'super_admin'
                    ? ' A Super Admin has full access to every workspace and the Control Center.'
                    : elevated
                      ? ' This changes business-wide access.'
                      : option(next.role)?.custom
                        ? ' Access follows the ' +
                          label(next.role) +
                          ' custom role baseline.'
                        : ''),
        );
        if (member.custom_access_count > 0) {
            warnings.push(
                `${member.custom_access_count} custom permission${member.custom_access_count === 1 ? '' : 's'} will be removed; the account follows the new role baseline.`,
            );
        }
        if (nextBusinessWide && !member.business_wide) {
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
    if (removed.length > 0 && !nextBusinessWide) {
        warnings.push(
            `Branch access removed: ${removed.map((id) => branchNames[id] ?? 'a Branch').join(', ')}.`,
        );
    }

    return warnings;
}
