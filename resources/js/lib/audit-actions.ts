/** Readable Audit Trail action names shared by the Audit Trail and the Executive Dashboard. */
const ACTION_LABELS: Record<string, string> = {
    'auth.login': 'User signed in',
    'order.created': 'Order created',
    'order.paid': 'Order paid',
    'order.voided': 'Order voided',
    'void_pin.configured': 'Void PIN configured',
    'staff.created': 'Staff account created',
    'staff.updated': 'Staff details updated',
    'staff.role_changed': 'Staff role changed',
    'staff.branch_access_changed': 'Staff Branch access changed',
    'staff.deactivated': 'Staff account deactivated',
    'staff.reactivated': 'Staff account reactivated',
    'staff.avatar_updated': 'Staff photo updated',
    'staff.avatar_removed': 'Staff photo removed',
    'staff.password_reset': 'Staff password reset',
    'access.role_permissions_updated': 'Role permissions changed',
    'access.user_override_updated': 'User custom access changed',
    'access.user_overrides_reset': 'User custom access reset',
    'access.custom_role_created': 'Custom role created',
    'access.custom_role_updated': 'Custom role renamed or rescoped',
    'access.custom_role_permissions_updated': 'Custom role permissions changed',
    'access.custom_role_archived': 'Custom role archived',
};

export function titleCase(value: string): string {
    return value.replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export function auditActionLabel(action: string): string {
    return (
        ACTION_LABELS[action] ??
        titleCase(action.replaceAll('.', ' ').replaceAll('_', ' '))
    );
}
