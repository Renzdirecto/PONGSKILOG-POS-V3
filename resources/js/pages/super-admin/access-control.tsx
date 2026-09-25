import { Head, Link, router } from '@inertiajs/react';
import {
    Archive,
    Check,
    Globe2,
    Lock,
    Minus,
    Pencil,
    Plus,
    RotateCcw,
    Search,
    ShieldCheck,
    Store,
    UserRound,
    Users,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerControlClass,
    ownerPanelClass,
    ownerPrimaryActionClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    ArchiveCustomRoleDialog,
    CreateCustomRoleDialog,
    EditCustomRoleDialog,
    type CustomRoleBuilderContext,
} from '@/components/custom-role-dialogs';
import { Spinner } from '@/components/ui/spinner';
import {
    EFFECTIVE_STATE_LABELS,
    OVERRIDE_LABELS,
    effectiveState,
    groupPermissions,
    isRedundantOverride,
    meaningfulOverrides,
    permissionDiff,
    sameOverrides,
    type EffectiveState,
    type OverrideEffect,
    type PermissionMeta,
    type RoleScope,
} from '@/lib/access-control';
import { accessControl } from '@/routes/super-admin';
import { update as updateCustomRole } from '@/routes/super-admin/access-control/custom-roles';
import { update as updateRole } from '@/routes/super-admin/access-control/roles';
import {
    reset as resetUser,
    update as updateUser,
} from '@/routes/super-admin/access-control/users';
import type { BranchSummary } from '@/types';

type RoleMember = {
    id: number;
    name: string;
    employee_id: string | null;
    is_active: boolean;
};
type RoleBaseline = {
    id: number;
    name: string;
    label: string;
    kind: 'editable' | 'derived' | 'locked' | 'custom' | 'archived';
    scope: RoleScope;
    business_wide: boolean;
    permissions: string[];
    locks: Record<string, string | null>;
    assigned_count: number;
    members: RoleMember[];
};
type StaffOption = {
    id: number;
    name: string;
    employee_id: string | null;
    is_active: boolean;
    role: string | null;
    role_label: string;
    custom_count: number;
};
type SelectedAccount = {
    id: number;
    name: string;
    email: string;
    employee_id: string | null;
    is_active: boolean;
    role: string | null;
    role_label: string;
    custom_role?: boolean;
    business_wide: boolean;
    branches: BranchSummary[];
    lock_reason: string | null;
    baseline: string[];
    overrides: Record<string, 'allow' | 'deny'>;
    effective: string[];
    locks: Record<string, string | null>;
};
type Props = {
    permissions: PermissionMeta[];
    categories: Record<string, string>;
    roles: RoleBaseline[];
    staff: StaffOption[];
    selected: SelectedAccount | null;
    filters: { tab: 'roles' | 'staff'; role: string | null; search: string };
    scopes: Record<RoleScope, string>;
    scopeLocks: Record<RoleScope, Record<string, string | null>>;
    roleNameMax: number;
};

const stateTones: Record<EffectiveState, 'green' | 'blue' | 'neutral' | 'red'> =
    {
        role: 'green',
        custom: 'blue',
        none: 'neutral',
        removed: 'red',
    };

function visit(query: Record<string, string | number | undefined>) {
    router.get(
        accessControl.url(),
        Object.fromEntries(
            Object.entries(query).filter(
                ([, value]) => value !== undefined && value !== '',
            ),
        ),
        { preserveScroll: true, preserveState: true, replace: true },
    );
}

export default function AccessControl({
    permissions,
    categories,
    roles,
    staff,
    selected,
    filters,
    scopes,
    scopeLocks,
    roleNameMax,
}: Props) {
    const groups = groupPermissions(permissions, categories);
    const labels = Object.fromEntries(
        permissions.map((permission) => [permission.key, permission.label]),
    );
    const builder: CustomRoleBuilderContext = {
        groups,
        labels,
        scopes,
        scopeLocks,
        roleNameMax,
        takenLabels: roles
            .filter((role) => role.kind !== 'archived')
            .map((role) => role.label),
    };

    return (
        <>
            <Head title="Access Control" />
            <OwnerPage
                title="Access Control"
                description="A role sets each account's baseline access: a built-in System role or a Custom Role you create. Custom access adds or removes single permissions for one account."
                maxWidth="max-w-[1180px]"
            >
                <nav
                    aria-label="Access Control views"
                    className="flex w-full rounded-[12px] bg-[#ededed] p-1 sm:w-fit"
                >
                    {(
                        [
                            ['roles', 'Roles'],
                            ['staff', 'Staff overrides'],
                        ] as const
                    ).map(([tab, label]) => (
                        <Link
                            key={tab}
                            href={accessControl({
                                query:
                                    tab === 'staff' && selected
                                        ? { tab, user: selected.id }
                                        : { tab },
                            })}
                            preserveScroll
                            aria-current={
                                filters.tab === tab ? 'page' : undefined
                            }
                            className={`flex min-h-11 flex-1 items-center justify-center rounded-[9px] px-4 text-[13px] font-semibold sm:flex-none ${filters.tab === tab ? 'bg-white text-[#111] shadow-sm' : 'text-[#666]'}`}
                        >
                            {label}
                        </Link>
                    ))}
                </nav>

                {filters.tab === 'roles' ? (
                    <RolesView
                        roles={roles}
                        groups={groups}
                        labels={labels}
                        initialRole={filters.role}
                        builder={builder}
                    />
                ) : (
                    <StaffOverridesView
                        staff={staff}
                        selected={selected}
                        groups={groups}
                        labels={labels}
                        search={filters.search}
                    />
                )}
            </OwnerPage>
        </>
    );
}

function RolesView({
    roles,
    groups,
    labels,
    initialRole,
    builder,
}: {
    roles: RoleBaseline[];
    groups: ReturnType<typeof groupPermissions>;
    labels: Record<string, string>;
    initialRole: string | null;
    builder: CustomRoleBuilderContext;
}) {
    const [roleName, setRoleName] = useState(
        roles.some((role) => role.name === initialRole)
            ? (initialRole as string)
            : 'cashier',
    );
    const role = roles.find((item) => item.name === roleName) ?? roles[0];
    const [draft, setDraft] = useState<string[]>(role.permissions);
    const [draftFor, setDraftFor] = useState(
        `${role.name}:${role.permissions.join(',')}`,
    );
    const [confirming, setConfirming] = useState(false);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const [dialog, setDialog] = useState<'create' | 'edit' | 'archive' | null>(
        null,
    );
    const snapshot = `${role.name}:${role.permissions.join(',')}`;

    if (draftFor !== snapshot) {
        setDraftFor(snapshot);
        setDraft(role.permissions);
        setError(null);
    }

    const custom = role.kind === 'custom';
    const editable = role.kind === 'editable' || custom;
    const systemRoles = roles.filter(
        (item) => item.kind !== 'custom' && item.kind !== 'archived',
    );
    const customRoles = roles.filter((item) => item.kind === 'custom');
    const archivedRoles = roles.filter((item) => item.kind === 'archived');
    const diff = permissionDiff(role.permissions, draft);
    const dirty = diff.added.length > 0 || diff.removed.length > 0;

    function toggle(permission: string, included: boolean) {
        setDraft((current) =>
            included
                ? [...current, permission]
                : current.filter((item) => item !== permission),
        );
    }

    function save() {
        setSaving(true);
        const permissions = draft.filter(
            (permission) => role.locks[permission] === null,
        );
        router.put(
            custom ? updateCustomRole.url(role.id) : updateRole.url(role.name),
            custom
                ? { label: role.label, scope: role.scope, permissions }
                : { permissions },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        errors.permissions ?? errors.role ?? 'Could not save.',
                    ),
                onSuccess: () => setConfirming(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <>
            <section
                aria-labelledby="system-roles-title"
                className="grid gap-2"
            >
                <h2
                    id="system-roles-title"
                    className="text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase"
                >
                    System roles
                </h2>
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
                    {systemRoles.map((item) => (
                        <RoleButton
                            key={item.name}
                            role={item}
                            active={item.name === role.name}
                            onSelect={() => setRoleName(item.name)}
                        />
                    ))}
                </div>
            </section>

            <section
                aria-labelledby="custom-roles-title"
                className="grid gap-2"
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2
                        id="custom-roles-title"
                        className="text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase"
                    >
                        Custom roles
                    </h2>
                    <button
                        type="button"
                        onClick={() => setDialog('create')}
                        className={`${ownerPrimaryActionClass} inline-flex items-center gap-1.5`}
                    >
                        <Plus className="size-4" aria-hidden="true" />
                        Create custom role
                    </button>
                </div>
                {customRoles.length === 0 ? (
                    <p className="rounded-[14px] border border-dashed border-[#d9d9d9] bg-white px-4 py-5 text-center text-[12.5px] text-[#767676]">
                        No custom roles yet. Create one for a group of Staff who
                        share the same access, for example a Branch Supervisor.
                    </p>
                ) : (
                    <div className="grid grid-cols-1 gap-2 min-[420px]:grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                        {customRoles.map((item) => (
                            <RoleButton
                                key={item.name}
                                role={item}
                                active={item.name === role.name}
                                onSelect={() => setRoleName(item.name)}
                            />
                        ))}
                    </div>
                )}
                {archivedRoles.length > 0 && (
                    <details className="rounded-[12px] border border-[#ececec] bg-white px-3 py-2 text-[12.5px]">
                        <summary className="min-h-9 cursor-pointer content-center font-semibold text-[#555]">
                            Archived roles ({archivedRoles.length})
                        </summary>
                        <div className="mt-2 grid grid-cols-1 gap-2 min-[420px]:grid-cols-2 sm:grid-cols-3 lg:grid-cols-5">
                            {archivedRoles.map((item) => (
                                <RoleButton
                                    key={item.name}
                                    role={item}
                                    active={item.name === role.name}
                                    onSelect={() => setRoleName(item.name)}
                                />
                            ))}
                        </div>
                    </details>
                )}
            </section>

            <section className={`${ownerPanelClass} p-4 md:p-5`}>
                <div className="flex flex-wrap items-start justify-between gap-3">
                    <div className="min-w-0">
                        <h2 className="text-[16px] font-semibold">
                            {role.label} baseline
                        </h2>
                        <p className="mt-1 max-w-[70ch] text-[12.5px] leading-5 text-[#666]">
                            {role.kind === 'locked'
                                ? 'Super Admin always has every permission. It cannot be reduced, so the Control Center can never be locked out.'
                                : role.kind === 'derived'
                                  ? 'Cashier + Kitchen is always the combination of the Cashier and Kitchen Staff baselines. Change those roles to change it.'
                                  : role.kind === 'archived'
                                    ? 'Archived: this custom role can no longer be assigned. Its baseline is kept for the audit record.'
                                    : `Every ${role.label} account gets these permissions unless custom access changes one of them for a single account.${custom ? (role.business_wide ? ' Business-wide: reaches every Branch without Branch assignments.' : ' Branch role: works only at each account’s assigned Branches.') : ''}`}
                        </p>
                    </div>
                    <OwnerStatusBadge
                        tone={
                            role.kind === 'locked'
                                ? 'green'
                                : role.kind === 'derived'
                                  ? 'blue'
                                  : 'outline'
                        }
                    >
                        {role.kind === 'locked'
                            ? 'Locked · Full access'
                            : role.kind === 'derived'
                              ? 'Derived from Cashier + Kitchen Staff'
                              : role.kind === 'archived'
                                ? 'Archived'
                                : `${custom ? 'Custom' : 'Editable'} · ${role.business_wide ? 'business-wide' : 'Branch-scoped'}`}
                    </OwnerStatusBadge>
                </div>

                {custom && (
                    <div className="mt-3 flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={() => setDialog('edit')}
                            className={`${ownerSecondaryActionClass} inline-flex items-center gap-1.5`}
                        >
                            <Pencil className="size-4" aria-hidden="true" />
                            Rename or change scope
                        </button>
                        <button
                            type="button"
                            disabled={role.assigned_count > 0}
                            title={
                                role.assigned_count > 0
                                    ? 'Reassign its staff in Staff before archiving.'
                                    : undefined
                            }
                            onClick={() => setDialog('archive')}
                            className={`${ownerSecondaryActionClass} inline-flex items-center gap-1.5 disabled:opacity-50`}
                        >
                            <Archive className="size-4" aria-hidden="true" />
                            Archive
                        </button>
                        {role.assigned_count > 0 && (
                            <p className="w-full text-[11.5px] text-[#767676]">
                                Assigned roles cannot be archived. Reassign its
                                staff in Staff first.
                            </p>
                        )}
                    </div>
                )}

                {(custom || role.kind === 'archived') && (
                    <RoleMembers role={role} />
                )}

                <div className="mt-4 flex flex-col gap-5">
                    {groups.map((group) => (
                        <fieldset key={group.category} className="min-w-0">
                            <legend className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase">
                                {group.label}
                            </legend>
                            <ul className="divide-y divide-[#f0f0f0] rounded-[14px] border border-[#ececec]">
                                {group.permissions.map((permission) => {
                                    const lock = role.locks[permission.key];
                                    const included = draft.includes(
                                        permission.key,
                                    );
                                    const inputId = `role-${role.name}-${permission.key}`;
                                    const canToggle = editable && lock === null;

                                    return (
                                        <li
                                            key={permission.key}
                                            className="flex items-start gap-3 p-3"
                                        >
                                            {canToggle ? (
                                                <input
                                                    id={inputId}
                                                    type="checkbox"
                                                    checked={included}
                                                    onChange={(event) =>
                                                        toggle(
                                                            permission.key,
                                                            event.target
                                                                .checked,
                                                        )
                                                    }
                                                    aria-describedby={`${inputId}-help`}
                                                    className="mt-0.5 size-5 shrink-0 accent-[#111]"
                                                />
                                            ) : (
                                                <span
                                                    className="mt-0.5 flex size-5 shrink-0 items-center justify-center text-[#999]"
                                                    aria-hidden="true"
                                                >
                                                    {included ? (
                                                        <Check className="size-4 text-emerald-700" />
                                                    ) : lock ? (
                                                        <Lock className="size-3.5" />
                                                    ) : (
                                                        <Minus className="size-4" />
                                                    )}
                                                </span>
                                            )}
                                            <div className="min-w-0 flex-1">
                                                <label
                                                    htmlFor={
                                                        canToggle
                                                            ? inputId
                                                            : undefined
                                                    }
                                                    className="text-[13px] font-semibold"
                                                >
                                                    {permission.label}
                                                </label>
                                                <p
                                                    id={`${inputId}-help`}
                                                    className="text-[12px] leading-5 text-[#666]"
                                                >
                                                    {permission.description}
                                                    {!editable || lock === null
                                                        ? ''
                                                        : ` Locked: ${lock}`}
                                                </p>
                                            </div>
                                            <OwnerStatusBadge
                                                tone={
                                                    included
                                                        ? 'green'
                                                        : 'neutral'
                                                }
                                            >
                                                {role.kind === 'derived' &&
                                                included
                                                    ? 'Derived'
                                                    : included
                                                      ? 'Included'
                                                      : lock && editable
                                                        ? 'Locked'
                                                        : 'Not included'}
                                            </OwnerStatusBadge>
                                        </li>
                                    );
                                })}
                            </ul>
                        </fieldset>
                    ))}
                </div>

                {editable && (
                    <div className="sticky bottom-[calc(80px+env(safe-area-inset-bottom,0px))] mt-4 flex flex-wrap items-center justify-end gap-2 rounded-[14px] border border-[#ececec] bg-white/95 p-3 backdrop-blur md:bottom-3">
                        <p
                            role="status"
                            className="mr-auto text-[12px] text-[#666]"
                        >
                            {dirty
                                ? `${diff.added.length} to add, ${diff.removed.length} to remove`
                                : 'No unsaved changes'}
                        </p>
                        <button
                            type="button"
                            disabled={!dirty || saving}
                            onClick={() => setDraft(role.permissions)}
                            className={`${ownerSecondaryActionClass} disabled:opacity-50`}
                        >
                            Discard
                        </button>
                        <button
                            type="button"
                            disabled={!dirty || saving}
                            onClick={() => {
                                setError(null);
                                setConfirming(true);
                            }}
                            className={`${ownerPrimaryActionClass} disabled:opacity-50`}
                        >
                            Save {role.label} access
                        </button>
                    </div>
                )}
            </section>

            <RoleMatrix
                roles={roles.filter((item) => item.kind !== 'archived')}
                groups={groups}
            />

            <CreateCustomRoleDialog
                open={dialog === 'create'}
                onOpenChange={(open) => setDialog(open ? 'create' : null)}
                context={builder}
            />
            {custom && dialog === 'edit' && (
                <EditCustomRoleDialog
                    key={`${role.id}:${role.label}:${role.scope}`}
                    role={role}
                    open
                    onOpenChange={(open) => setDialog(open ? 'edit' : null)}
                    context={builder}
                />
            )}
            {custom && dialog === 'archive' && (
                <ArchiveCustomRoleDialog
                    role={role}
                    open
                    onOpenChange={(open) => setDialog(open ? 'archive' : null)}
                />
            )}

            <Dialog open={confirming} onOpenChange={setConfirming}>
                <DialogContent className="owner-surface sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>Change {role.label} access?</DialogTitle>
                        <DialogDescription>
                            This applies to every {role.label} account
                            {custom
                                ? ` (${role.assigned_count} now) on their next page`
                                : ''}
                            {role.name === 'cashier' ||
                            role.name === 'kitchen_staff'
                                ? ' and to Cashier + Kitchen, which combines Cashier and Kitchen Staff'
                                : ''}
                            . Custom access on single accounts stays as it is.
                        </DialogDescription>
                    </DialogHeader>
                    <ChangeSummary
                        added={diff.added}
                        removed={diff.removed}
                        labels={labels}
                    />
                    {error && (
                        <p role="alert" className="text-[12.5px] text-red-700">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={() => setConfirming(false)}
                            className={ownerSecondaryActionClass}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            onClick={save}
                            disabled={saving}
                            className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2`}
                        >
                            {saving && <Spinner />}
                            Save changes
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </>
    );
}

/** Read-only overview of every Role at once; hidden on phones, where the grouped cards above are the view. */
function RoleMatrix({
    roles,
    groups,
}: {
    roles: RoleBaseline[];
    groups: ReturnType<typeof groupPermissions>;
}) {
    return (
        <section
            aria-labelledby="role-matrix-title"
            className={`${ownerPanelClass} hidden overflow-hidden md:block`}
        >
            <h2
                id="role-matrix-title"
                className="border-b border-[#eeeeee] px-4 py-3 text-[13px] font-semibold"
            >
                All roles at a glance
            </h2>
            <div className="overflow-x-auto">
                <table
                    className="w-full table-fixed text-left text-[12px]"
                    style={{ minWidth: `${220 + roles.length * 96}px` }}
                >
                    <thead className="bg-[#fafafa] text-[10px] font-semibold tracking-[0.06em] text-[#888] uppercase">
                        <tr>
                            <th scope="col" className="w-[220px] px-4 py-2.5">
                                Permission
                            </th>
                            {roles.map((role) => (
                                <th
                                    key={role.name}
                                    scope="col"
                                    className="px-2 py-2.5 text-center"
                                >
                                    {role.label}
                                </th>
                            ))}
                        </tr>
                    </thead>
                    <tbody className="divide-y divide-[#f3f3f3]">
                        {groups.flatMap((group) =>
                            group.permissions.map((permission) => (
                                <tr key={permission.key}>
                                    <th
                                        scope="row"
                                        className="px-4 py-2 font-medium"
                                    >
                                        {permission.label}
                                    </th>
                                    {roles.map((role) => {
                                        const included =
                                            role.permissions.includes(
                                                permission.key,
                                            );
                                        const lock = role.locks[permission.key];

                                        return (
                                            <td
                                                key={role.name}
                                                className="px-2 py-2 text-center"
                                            >
                                                {included ? (
                                                    <span className="inline-flex items-center gap-1 text-emerald-700">
                                                        <Check
                                                            className="size-3.5"
                                                            aria-hidden="true"
                                                        />
                                                        <span className="sr-only md:not-sr-only md:text-[11px]">
                                                            Yes
                                                        </span>
                                                    </span>
                                                ) : lock &&
                                                  (role.kind === 'editable' ||
                                                      role.kind ===
                                                          'custom') ? (
                                                    <span
                                                        title={lock}
                                                        className="inline-flex items-center gap-1 text-[#999]"
                                                    >
                                                        <Lock
                                                            className="size-3"
                                                            aria-hidden="true"
                                                        />
                                                        <span className="text-[11px]">
                                                            Locked
                                                        </span>
                                                    </span>
                                                ) : (
                                                    <span className="text-[11px] text-[#999]">
                                                        No
                                                    </span>
                                                )}
                                            </td>
                                        );
                                    })}
                                </tr>
                            )),
                        )}
                    </tbody>
                </table>
            </div>
        </section>
    );
}

function RoleButton({
    role,
    active,
    onSelect,
}: {
    role: RoleBaseline;
    active: boolean;
    onSelect: () => void;
}) {
    return (
        <button
            type="button"
            aria-pressed={active}
            onClick={onSelect}
            className={`flex min-h-14 min-w-0 flex-col items-start justify-center rounded-[14px] border px-3 py-2 text-left transition focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${active ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white text-[#111] hover:border-[#bbb]'}`}
        >
            <span className="w-full truncate text-[13px] font-semibold">
                {role.label}
            </span>
            <span
                className={`text-[11px] ${active ? 'text-white/70' : 'text-[#777]'}`}
            >
                {role.kind === 'locked'
                    ? 'Locked · Full access'
                    : role.kind === 'derived'
                      ? 'Derived'
                      : role.kind === 'custom' || role.kind === 'archived'
                        ? `${role.business_wide ? 'Business-wide' : 'Branch'} · ${role.assigned_count} staff`
                        : `${role.permissions.length} permissions`}
            </span>
        </button>
    );
}

function RoleMembers({ role }: { role: RoleBaseline }) {
    return (
        <section
            aria-label={`Staff with the ${role.label} role`}
            className="mt-4 rounded-[14px] border border-[#ececec] bg-[#fafafa] p-3"
        >
            <h3 className="flex items-center gap-1.5 text-[12.5px] font-semibold">
                <Users className="size-4 text-[#777]" aria-hidden="true" />
                Assigned staff ({role.assigned_count})
            </h3>
            {role.members.length === 0 ? (
                <p className="mt-1 text-[12px] text-[#767676]">
                    Nobody holds this role yet. Assign it from Staff.
                </p>
            ) : (
                <ul className="mt-2 flex flex-wrap gap-1.5">
                    {role.members.map((member) => (
                        <li key={member.id}>
                            <Link
                                href={accessControl({
                                    query: { tab: 'staff', user: member.id },
                                })}
                                className="inline-flex min-h-9 items-center gap-1 rounded-full border border-[#e2e2e2] bg-white px-3 text-[12px] font-medium hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                            >
                                {member.name}
                                {!member.is_active && (
                                    <span className="text-[#999]">
                                        · Inactive
                                    </span>
                                )}
                            </Link>
                        </li>
                    ))}
                    {role.assigned_count > role.members.length && (
                        <li className="self-center text-[12px] text-[#767676]">
                            +{role.assigned_count - role.members.length} more
                        </li>
                    )}
                </ul>
            )}
        </section>
    );
}

function ChangeSummary({
    added,
    removed,
    labels,
}: {
    added: string[];
    removed: string[];
    labels: Record<string, string>;
}) {
    return (
        <dl className="grid gap-2 rounded-[12px] border border-[#ececec] bg-[#fafafa] p-3 text-[12.5px]">
            <div>
                <dt className="font-semibold">Adds</dt>
                <dd className="text-[#555]">
                    {added.length === 0
                        ? 'Nothing'
                        : added.map((key) => labels[key] ?? key).join(', ')}
                </dd>
            </div>
            <div>
                <dt className="font-semibold">Removes</dt>
                <dd className="text-[#555]">
                    {removed.length === 0
                        ? 'Nothing'
                        : removed.map((key) => labels[key] ?? key).join(', ')}
                </dd>
            </div>
        </dl>
    );
}

function StaffOverridesView({
    staff,
    selected,
    groups,
    labels,
    search: initialSearch,
}: {
    staff: StaffOption[];
    selected: SelectedAccount | null;
    groups: ReturnType<typeof groupPermissions>;
    labels: Record<string, string>;
    search: string;
}) {
    const [search, setSearch] = useState(initialSearch);
    const timer = useRef<number | undefined>(undefined);

    useEffect(() => () => window.clearTimeout(timer.current), []);

    function changeSearch(value: string) {
        setSearch(value);
        window.clearTimeout(timer.current);
        timer.current = window.setTimeout(
            () => visit({ tab: 'staff', search: value, user: selected?.id }),
            350,
        );
    }

    return (
        <div className="grid gap-3 lg:grid-cols-[320px_minmax(0,1fr)]">
            <section
                aria-label="Choose a staff account"
                className={`${ownerPanelClass} flex min-w-0 flex-col gap-2 p-3`}
            >
                <label className="relative block">
                    <span className="sr-only">
                        Search staff by name, email or Employee ID
                    </span>
                    <Search
                        className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#999]"
                        aria-hidden="true"
                    />
                    <input
                        type="search"
                        value={search}
                        maxLength={150}
                        onChange={(event) => changeSearch(event.target.value)}
                        placeholder="Search name, email or ID"
                        className={`${ownerControlClass} w-full pl-9`}
                    />
                </label>
                {staff.length === 0 ? (
                    <p className="px-1 py-4 text-center text-[12.5px] text-[#777]">
                        No staff accounts match.
                    </p>
                ) : (
                    <ul className="flex max-h-[420px] flex-col gap-1 overflow-y-auto lg:max-h-[640px]">
                        {staff.map((member) => (
                            <li key={member.id}>
                                <Link
                                    href={accessControl({
                                        query: {
                                            tab: 'staff',
                                            user: member.id,
                                            ...(search ? { search } : {}),
                                        },
                                    })}
                                    preserveScroll
                                    preserveState
                                    aria-current={
                                        selected?.id === member.id
                                            ? 'true'
                                            : undefined
                                    }
                                    className={`flex min-h-12 items-center gap-2 rounded-[10px] px-3 py-2 text-left focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${selected?.id === member.id ? 'bg-[#111] text-white' : 'hover:bg-[#f5f5f5]'}`}
                                >
                                    <span className="min-w-0 flex-1">
                                        <span className="block truncate text-[13px] font-semibold">
                                            {member.name}
                                        </span>
                                        <span
                                            className={`block truncate text-[11px] ${selected?.id === member.id ? 'text-white/70' : 'text-[#777]'}`}
                                        >
                                            {member.role_label}
                                            {member.employee_id
                                                ? ` · ${member.employee_id}`
                                                : ''}
                                            {member.is_active
                                                ? ''
                                                : ' · Inactive'}
                                        </span>
                                    </span>
                                    {member.custom_count > 0 && (
                                        <span
                                            className={`rounded-full px-2 text-[10px] leading-5 font-semibold ${selected?.id === member.id ? 'bg-white text-[#111]' : 'bg-blue-50 text-blue-800'}`}
                                        >
                                            {member.custom_count} custom
                                        </span>
                                    )}
                                </Link>
                            </li>
                        ))}
                    </ul>
                )}
            </section>

            {selected === null ? (
                <section
                    className={`${ownerPanelClass} flex flex-col items-center justify-center px-5 py-12 text-center`}
                >
                    <UserRound
                        className="size-7 text-[#aaa]"
                        aria-hidden="true"
                    />
                    <h2 className="mt-3 text-sm font-semibold">
                        Choose a staff account
                    </h2>
                    <p className="mt-1 max-w-[46ch] text-[12.5px] text-[#767676]">
                        Custom access adds or removes single permissions for one
                        account. The account keeps its role and its Branch
                        access.
                    </p>
                </section>
            ) : (
                <AccountOverrides
                    key={`${selected.id}:${JSON.stringify(selected.overrides)}:${selected.baseline.join(',')}`}
                    account={selected}
                    groups={groups}
                    labels={labels}
                />
            )}
        </div>
    );
}

function AccountOverrides({
    account,
    groups,
    labels,
}: {
    account: SelectedAccount;
    groups: ReturnType<typeof groupPermissions>;
    labels: Record<string, string>;
}) {
    const [choices, setChoices] = useState<Record<string, OverrideEffect>>(
        account.overrides,
    );
    const [confirm, setConfirm] = useState<'save' | 'reset' | null>(null);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const locked = account.lock_reason !== null;
    const submitted = meaningfulOverrides(choices, account.baseline);
    const dirty = !sameOverrides(submitted, account.overrides);
    const customCount = Object.keys(account.overrides).length;

    function choose(permission: string, effect: OverrideEffect) {
        setChoices((current) => ({ ...current, [permission]: effect }));
    }

    function save() {
        setSaving(true);
        setError(null);
        router.put(
            updateUser.url(account.id),
            { overrides: submitted },
            {
                preserveScroll: true,
                onError: (errors) =>
                    setError(
                        Object.values(errors)[0] ??
                            'Could not save custom access.',
                    ),
                onSuccess: () => setConfirm(null),
                onFinish: () => setSaving(false),
            },
        );
    }

    function reset() {
        setSaving(true);
        setError(null);
        router.delete(resetUser.url(account.id), {
            preserveScroll: true,
            onError: (errors) =>
                setError(Object.values(errors)[0] ?? 'Could not reset.'),
            onSuccess: () => setConfirm(null),
            onFinish: () => setSaving(false),
        });
    }

    const changes = Object.keys({ ...submitted, ...account.overrides })
        .map((permission) => ({
            permission,
            from: account.overrides[permission] ?? 'inherit',
            to: submitted[permission] ?? 'inherit',
        }))
        .filter((change) => change.from !== change.to);

    return (
        <section
            aria-labelledby="account-overrides-title"
            className={`${ownerPanelClass} min-w-0 p-4 md:p-5`}
        >
            <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                    <h2
                        id="account-overrides-title"
                        className="text-[16px] font-semibold wrap-break-word"
                    >
                        {account.name}
                    </h2>
                    <p className="font-mono text-[11.5px] text-[#767676]">
                        {account.employee_id ?? 'No Employee ID'}
                    </p>
                    <p className="text-[12px] break-all text-[#666]">
                        {account.email}
                    </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    <OwnerStatusBadge tone="neutral">
                        {account.role_label}
                    </OwnerStatusBadge>
                    <OwnerStatusBadge
                        tone={account.is_active ? 'green' : 'neutral'}
                    >
                        {account.is_active ? 'Active' : 'Inactive'}
                    </OwnerStatusBadge>
                </div>
            </div>
            <p className="mt-3 flex items-start gap-2 rounded-[11px] border border-[#ececec] bg-[#fafafa] p-3 text-[12.5px] text-[#444]">
                {account.business_wide ? (
                    <Globe2
                        className="mt-0.5 size-4 shrink-0 text-[#888]"
                        aria-hidden="true"
                    />
                ) : (
                    <Store
                        className="mt-0.5 size-4 shrink-0 text-[#888]"
                        aria-hidden="true"
                    />
                )}
                <span>
                    <strong className="font-semibold">Branch access: </strong>
                    {account.business_wide
                        ? 'All branches / business-wide.'
                        : account.branches.length === 0
                          ? 'No active Branch.'
                          : `${account.branches.map((branch) => branch.name).join(', ')}. Custom access never widens this — it only changes what the account may do at these Branches.`}
                </span>
            </p>

            {locked ? (
                <div
                    role="note"
                    className="mt-4 flex gap-2.5 rounded-[11px] border border-emerald-200 bg-emerald-50 p-3 text-[12.5px] text-emerald-900"
                >
                    <ShieldCheck
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <span>{account.lock_reason}</span>
                </div>
            ) : (
                <div className="mt-4 flex flex-col gap-5">
                    {groups.map((group) => (
                        <div key={group.category}>
                            <h3 className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase">
                                {group.label}
                            </h3>
                            <ul className="divide-y divide-[#f0f0f0] rounded-[14px] border border-[#ececec]">
                                {group.permissions.map((permission) => (
                                    <OverrideRow
                                        key={permission.key}
                                        permission={permission}
                                        includedByRole={account.baseline.includes(
                                            permission.key,
                                        )}
                                        lock={
                                            account.locks[permission.key] ??
                                            null
                                        }
                                        effect={
                                            choices[permission.key] ?? 'inherit'
                                        }
                                        onChoose={(effect) =>
                                            choose(permission.key, effect)
                                        }
                                        disabled={saving}
                                    />
                                ))}
                            </ul>
                        </div>
                    ))}
                </div>
            )}

            {error && confirm === null && (
                <p role="alert" className="mt-3 text-[12.5px] text-red-700">
                    {error}
                </p>
            )}

            {!locked && (
                <div className="sticky bottom-[calc(80px+env(safe-area-inset-bottom,0px))] mt-4 flex flex-wrap items-center justify-end gap-2 rounded-[14px] border border-[#ececec] bg-white/95 p-3 backdrop-blur md:bottom-3">
                    <p
                        role="status"
                        className="mr-auto text-[12px] text-[#666]"
                    >
                        {dirty
                            ? `${changes.length} unsaved change${changes.length === 1 ? '' : 's'}`
                            : customCount === 0
                              ? 'Follows the role baseline'
                              : `${customCount} custom permission${customCount === 1 ? '' : 's'}`}
                    </p>
                    <button
                        type="button"
                        disabled={customCount === 0 || saving}
                        onClick={() => {
                            setError(null);
                            setConfirm('reset');
                        }}
                        className={`${ownerSecondaryActionClass} inline-flex items-center gap-1.5 disabled:opacity-50`}
                    >
                        <RotateCcw className="size-4" aria-hidden="true" />
                        Reset all custom access
                    </button>
                    <button
                        type="button"
                        disabled={!dirty || saving}
                        onClick={() => {
                            setError(null);
                            setConfirm('save');
                        }}
                        className={`${ownerPrimaryActionClass} disabled:opacity-50`}
                    >
                        Save custom access
                    </button>
                </div>
            )}

            <Dialog
                open={confirm !== null}
                onOpenChange={(open) => !open && setConfirm(null)}
            >
                <DialogContent className="owner-surface sm:max-w-md">
                    <DialogHeader>
                        <DialogTitle>
                            {confirm === 'reset'
                                ? `Reset custom access for ${account.name}?`
                                : `Save custom access for ${account.name}?`}
                        </DialogTitle>
                        <DialogDescription>
                            {confirm === 'reset'
                                ? `Every custom permission is removed and the account follows the ${account.role_label} role again.`
                                : `Only this account changes. Other ${account.role_label} accounts keep the role baseline.`}
                        </DialogDescription>
                    </DialogHeader>
                    {confirm === 'save' && (
                        <ul className="grid gap-1.5 rounded-[12px] border border-[#ececec] bg-[#fafafa] p-3 text-[12.5px]">
                            {changes.map((change) => (
                                <li key={change.permission}>
                                    <strong className="font-semibold">
                                        {labels[change.permission] ??
                                            change.permission}
                                    </strong>
                                    : {OVERRIDE_LABELS[change.from]} →{' '}
                                    {OVERRIDE_LABELS[change.to]}
                                </li>
                            ))}
                        </ul>
                    )}
                    {error && (
                        <p role="alert" className="text-[12.5px] text-red-700">
                            {error}
                        </p>
                    )}
                    <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            onClick={() => setConfirm(null)}
                            className={ownerSecondaryActionClass}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            disabled={saving}
                            onClick={confirm === 'reset' ? reset : save}
                            className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2`}
                        >
                            {saving && <Spinner />}
                            {confirm === 'reset'
                                ? 'Reset to role'
                                : 'Save changes'}
                        </button>
                    </div>
                </DialogContent>
            </Dialog>
        </section>
    );
}

function OverrideRow({
    permission,
    includedByRole,
    lock,
    effect,
    onChoose,
    disabled,
}: {
    permission: PermissionMeta;
    includedByRole: boolean;
    lock: string | null;
    effect: OverrideEffect;
    onChoose: (effect: OverrideEffect) => void;
    disabled: boolean;
}) {
    const state = effectiveState(
        includedByRole,
        lock === null ? effect : 'inherit',
    );
    const name = `override-${permission.key}`;

    return (
        <li className="flex flex-col gap-2 p-3 sm:flex-row sm:items-center">
            <div className="min-w-0 flex-1">
                <p id={`${name}-label`} className="text-[13px] font-semibold">
                    {permission.label}
                </p>
                <p
                    id={`${name}-help`}
                    className="text-[12px] leading-5 text-[#666]"
                >
                    Role default: {includedByRole ? 'Included' : 'No access'}
                    {lock ? ` · Locked: ${lock}` : ''}
                </p>
            </div>
            {lock === null ? (
                <div
                    role="radiogroup"
                    aria-labelledby={`${name}-label`}
                    aria-describedby={`${name}-help`}
                    className="grid grid-cols-3 gap-1 rounded-[11px] bg-[#f0f0f0] p-1 sm:w-[252px]"
                >
                    {(['inherit', 'allow', 'deny'] as const).map((option) => {
                        const redundant = isRedundantOverride(
                            includedByRole,
                            option,
                        );

                        return (
                            <label
                                key={option}
                                title={
                                    redundant
                                        ? `Same as the role default (${includedByRole ? 'Included' : 'No access'})`
                                        : undefined
                                }
                                className={`flex min-h-11 items-center justify-center rounded-[8px] text-[12px] font-semibold has-focus-visible:ring-2 has-focus-visible:ring-[#111] ${effect === option ? 'bg-[#111] text-white' : 'text-[#555]'} ${redundant ? 'cursor-not-allowed opacity-40' : 'cursor-pointer'}`}
                            >
                                <input
                                    type="radio"
                                    name={name}
                                    value={option}
                                    checked={effect === option}
                                    disabled={disabled || redundant}
                                    onChange={() => onChoose(option)}
                                    className="sr-only"
                                />
                                {OVERRIDE_LABELS[option]}
                            </label>
                        );
                    })}
                </div>
            ) : (
                <span className="inline-flex items-center gap-1 text-[12px] text-[#888]">
                    <Lock className="size-3.5" aria-hidden="true" />
                    Locked
                </span>
            )}
            <span className="sm:w-[150px] sm:text-right">
                <OwnerStatusBadge tone={stateTones[state]}>
                    {EFFECTIVE_STATE_LABELS[state]}
                </OwnerStatusBadge>
            </span>
        </li>
    );
}
