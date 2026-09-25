import { router } from '@inertiajs/react';
import { Check, Globe2, Lock, Store } from 'lucide-react';
import { useState } from 'react';
import {
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import {
    groupPermissions,
    normalizeRoleName,
    permissionsForScope,
    roleNameError,
    type RoleScope,
} from '@/lib/access-control';
import { requiredOutline } from '@/lib/required-field';
import {
    archive as archiveCustomRole,
    store as storeCustomRole,
    update as updateCustomRole,
} from '@/routes/super-admin/access-control/custom-roles';

type Groups = ReturnType<typeof groupPermissions>;

export type CustomRoleBuilderContext = {
    groups: Groups;
    labels: Record<string, string>;
    scopes: Record<RoleScope, string>;
    scopeLocks: Record<RoleScope, Record<string, string | null>>;
    roleNameMax: number;
    /** Display names of every active role (System included), for the duplicate-name check. */
    takenLabels: string[];
};

const SCOPE_HELP: Record<RoleScope, { icon: typeof Store; text: string }> = {
    branch: {
        icon: Store,
        text: 'Access is limited to assigned Branches. Branch operations and Reports only; never All Branches.',
    },
    business: {
        icon: Globe2,
        text: 'Access can span all Branches. Branch operations still require selecting a specific Branch. Never includes Control.',
    },
};

const STEPS = ['Name', 'Scope', 'Access', 'Review'] as const;

function firstError(errors: Record<string, string | undefined>): string {
    return (
        errors.label ??
        errors.scope ??
        errors.permissions ??
        errors.role ??
        Object.values(errors).find(Boolean) ??
        'Could not save.'
    );
}

export function ScopeChoice({
    scopes,
    value,
    onChange,
    disabledReason,
    name,
}: {
    scopes: Record<RoleScope, string>;
    value: RoleScope | '';
    onChange: (scope: RoleScope) => void;
    disabledReason?: string | null;
    name: string;
}) {
    const missing = value === '';

    return (
        <fieldset
            aria-describedby={missing ? `${name}-error` : undefined}
            className="grid gap-2"
        >
            <legend className="mb-1 text-sm font-medium">
                Access scope <span className="text-[#b91c1c]">*</span>
            </legend>
            {(Object.keys(scopes) as RoleScope[]).map((scope) => {
                const Icon = SCOPE_HELP[scope].icon;
                const selected = value === scope;

                return (
                    <label
                        key={scope}
                        className={`flex min-h-14 cursor-pointer items-start gap-3 rounded-[12px] bg-white p-3 has-focus-visible:ring-2 has-focus-visible:ring-[#111] ${requiredOutline(missing, selected)} ${disabledReason ? 'cursor-not-allowed opacity-60' : ''}`}
                    >
                        <input
                            type="radio"
                            name={name}
                            value={scope}
                            checked={selected}
                            disabled={Boolean(disabledReason)}
                            onChange={() => onChange(scope)}
                            className="sr-only"
                        />
                        <Icon
                            className="mt-0.5 size-4 shrink-0 text-[#666]"
                            aria-hidden="true"
                        />
                        <span className="min-w-0 flex-1">
                            <span className="block text-[13px] font-semibold">
                                {scopes[scope]} role
                            </span>
                            <span className="block text-[12px] leading-5 text-[#666]">
                                {SCOPE_HELP[scope].text}
                            </span>
                        </span>
                        {selected && (
                            <Check
                                className="size-4 shrink-0 text-emerald-700"
                                aria-hidden="true"
                            />
                        )}
                    </label>
                );
            })}
            {missing && (
                <p id={`${name}-error`} className="text-[12px] text-[#b91c1c]">
                    Required · choose where this role works.
                </p>
            )}
            {disabledReason && (
                <p className="text-[12px] text-[#666]">{disabledReason}</p>
            )}
        </fieldset>
    );
}

export function PermissionPicker({
    groups,
    locks,
    selected,
    onChange,
    idPrefix,
}: {
    groups: Groups;
    locks: Record<string, string | null>;
    selected: string[];
    onChange: (permissions: string[]) => void;
    idPrefix: string;
}) {
    return (
        <div className="flex flex-col gap-4">
            {groups.map((group) => (
                <fieldset key={group.category} className="min-w-0">
                    <legend className="mb-2 text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase">
                        {group.label}
                    </legend>
                    <ul className="divide-y divide-[#f0f0f0] rounded-[14px] border border-[#ececec]">
                        {group.permissions.map((permission) => {
                            const lock = locks[permission.key] ?? null;
                            const inputId = `${idPrefix}-${permission.key}`;
                            const checked = selected.includes(permission.key);

                            return (
                                <li
                                    key={permission.key}
                                    className="flex items-start gap-3 p-3"
                                >
                                    {lock === null ? (
                                        <input
                                            id={inputId}
                                            type="checkbox"
                                            checked={checked}
                                            onChange={(event) =>
                                                onChange(
                                                    event.target.checked
                                                        ? [
                                                              ...selected,
                                                              permission.key,
                                                          ]
                                                        : selected.filter(
                                                              (item) =>
                                                                  item !==
                                                                  permission.key,
                                                          ),
                                                )
                                            }
                                            aria-describedby={`${inputId}-help`}
                                            className="mt-0.5 size-5 shrink-0 accent-[#111]"
                                        />
                                    ) : (
                                        <Lock
                                            className="mt-1 size-3.5 shrink-0 text-[#999]"
                                            aria-hidden="true"
                                        />
                                    )}
                                    <div className="min-w-0 flex-1">
                                        <label
                                            htmlFor={
                                                lock === null
                                                    ? inputId
                                                    : undefined
                                            }
                                            className={`text-[13px] font-semibold ${lock === null ? '' : 'text-[#888]'}`}
                                        >
                                            {permission.label}
                                        </label>
                                        <p
                                            id={`${inputId}-help`}
                                            className="text-[12px] leading-5 text-[#666]"
                                        >
                                            {lock === null
                                                ? permission.description
                                                : `Locked: ${lock}`}
                                        </p>
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                </fieldset>
            ))}
        </div>
    );
}

export function CreateCustomRoleDialog({
    open,
    onOpenChange,
    context,
}: {
    open: boolean;
    onOpenChange: (open: boolean) => void;
    context: CustomRoleBuilderContext;
}) {
    const [step, setStep] = useState(0);
    const [name, setName] = useState('');
    const [scope, setScope] = useState<RoleScope | ''>('');
    const [permissions, setPermissions] = useState<string[]>([]);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const nameError = roleNameError(
        name,
        context.roleNameMax,
        context.takenLabels,
    );
    const locks = scope === '' ? {} : context.scopeLocks[scope];
    const chosen = permissionsForScope(permissions, locks);
    const canContinue = [nameError === null, scope !== '', true, true][step];

    function reset(next: boolean) {
        if (!next) {
            setStep(0);
            setName('');
            setScope('');
            setPermissions([]);
            setError(null);
        }
        onOpenChange(next);
    }

    function create() {
        if (scope === '') {
            return;
        }
        setSaving(true);
        setError(null);
        router.post(
            storeCustomRole.url(),
            { label: normalizeRoleName(name), scope, permissions: chosen },
            {
                preserveScroll: true,
                onError: (errors) => setError(firstError(errors)),
                onSuccess: () => reset(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={reset}>
            <DialogContent className="owner-surface max-h-[92dvh] overflow-y-auto sm:max-w-xl">
                <DialogHeader>
                    <DialogTitle>Create custom role</DialogTitle>
                    <DialogDescription>
                        A reusable permission package for a group of Staff. Each
                        account can still get single exceptions in Staff
                        overrides.
                    </DialogDescription>
                </DialogHeader>

                <ol
                    aria-label="Steps"
                    className="grid grid-cols-4 gap-1.5 text-[11px] font-semibold"
                >
                    {STEPS.map((label, index) => (
                        <li
                            key={label}
                            aria-current={index === step ? 'step' : undefined}
                            className={`rounded-[9px] px-2 py-1.5 text-center ${index === step ? 'bg-[#111] text-white' : index < step ? 'bg-emerald-50 text-emerald-800' : 'bg-[#f2f2f2] text-[#777]'}`}
                        >
                            {index + 1}. {label}
                        </li>
                    ))}
                </ol>

                {step === 0 && (
                    <div className="space-y-2">
                        <Label htmlFor="custom-role-name">
                            Role name <span className="text-[#b91c1c]">*</span>
                        </Label>
                        <Input
                            id="custom-role-name"
                            value={name}
                            maxLength={context.roleNameMax + 10}
                            autoFocus
                            placeholder="Branch Supervisor"
                            onChange={(event) => setName(event.target.value)}
                            aria-invalid={nameError !== null}
                            aria-describedby="custom-role-name-help"
                        />
                        <p
                            id="custom-role-name-help"
                            className={`text-[12px] ${nameError ? 'text-[#b91c1c]' : 'text-[#666]'}`}
                        >
                            {nameError ??
                                'Shown on Staff accounts and in the Audit Trail. You can rename it later.'}
                        </p>
                    </div>
                )}

                {step === 1 && (
                    <ScopeChoice
                        name="create-custom-role-scope"
                        scopes={context.scopes}
                        value={scope}
                        onChange={setScope}
                    />
                )}

                {step === 2 && scope !== '' && (
                    <>
                        <p className="rounded-[11px] border border-[#ececec] bg-[#fafafa] p-3 text-[12.5px] text-[#444]">
                            Choose the pages this {context.scopes[scope]} role
                            opens. Locked items stay visible with the reason;
                            Control (Audit Trail, Void Orders, Access Control)
                            is always Super Admin only.
                        </p>
                        <PermissionPicker
                            groups={context.groups}
                            locks={locks}
                            selected={chosen}
                            onChange={setPermissions}
                            idPrefix="create-custom-role"
                        />
                    </>
                )}

                {step === 3 && scope !== '' && (
                    <dl className="grid gap-2 rounded-[12px] border border-[#ececec] bg-[#fafafa] p-3 text-[12.5px]">
                        <div>
                            <dt className="font-semibold">Name</dt>
                            <dd className="text-[#555]">
                                {normalizeRoleName(name)}
                            </dd>
                        </div>
                        <div>
                            <dt className="font-semibold">Scope</dt>
                            <dd className="text-[#555]">
                                {context.scopes[scope]} ·{' '}
                                {SCOPE_HELP[scope].text}
                            </dd>
                        </div>
                        <div>
                            <dt className="font-semibold">Pages</dt>
                            <dd className="text-[#555]">
                                {chosen.length === 0
                                    ? 'None yet. Accounts with this role only get what their own custom access allows.'
                                    : chosen
                                          .map((key) => context.labels[key])
                                          .join(', ')}
                                {chosen.includes('pos.access') &&
                                    ' (QR Orders follow POS).'}
                            </dd>
                        </div>
                    </dl>
                )}

                {error && (
                    <p role="alert" className="text-[12.5px] text-red-700">
                        {error}
                    </p>
                )}

                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={() =>
                            step === 0 ? reset(false) : setStep(step - 1)
                        }
                        className={ownerSecondaryActionClass}
                    >
                        {step === 0 ? 'Cancel' : 'Back'}
                    </button>
                    {step < STEPS.length - 1 ? (
                        <button
                            type="button"
                            disabled={!canContinue}
                            onClick={() => setStep(step + 1)}
                            className={`${ownerPrimaryActionClass} disabled:opacity-50`}
                        >
                            Continue
                        </button>
                    ) : (
                        <button
                            type="button"
                            disabled={saving}
                            onClick={create}
                            className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2`}
                        >
                            {saving && <Spinner />}
                            Create role
                        </button>
                    )}
                </div>
            </DialogContent>
        </Dialog>
    );
}

export type EditableCustomRole = {
    id: number;
    label: string;
    scope: RoleScope;
    permissions: string[];
    assigned_count: number;
};

/** Rename a Custom Role and, while nobody holds it, change its scope. The permission baseline is kept. */
export function EditCustomRoleDialog({
    role,
    open,
    onOpenChange,
    context,
}: {
    role: EditableCustomRole;
    open: boolean;
    onOpenChange: (open: boolean) => void;
    context: CustomRoleBuilderContext;
}) {
    const [name, setName] = useState(role.label);
    const [scope, setScope] = useState<RoleScope | ''>(role.scope);
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);
    const nameError = roleNameError(
        name,
        context.roleNameMax,
        context.takenLabels.filter(
            (label) =>
                label.toLocaleLowerCase() !== role.label.toLocaleLowerCase(),
        ),
    );
    const locks = scope === '' ? {} : context.scopeLocks[scope];
    const kept = permissionsForScope(role.permissions, locks);
    const dropped = role.permissions.filter(
        (permission) =>
            !kept.includes(permission) && permission !== 'qr_orders.access',
    );
    const assigned = role.assigned_count > 0;

    function save() {
        if (scope === '') {
            return;
        }
        setSaving(true);
        setError(null);
        router.put(
            updateCustomRole.url(role.id),
            { label: normalizeRoleName(name), scope, permissions: kept },
            {
                preserveScroll: true,
                onError: (errors) => setError(firstError(errors)),
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="owner-surface max-h-[92dvh] overflow-y-auto sm:max-w-lg">
                <DialogHeader>
                    <DialogTitle>Edit {role.label}</DialogTitle>
                    <DialogDescription>
                        Renaming keeps every assignment, permission and audit
                        record.
                    </DialogDescription>
                </DialogHeader>
                <div className="space-y-2">
                    <Label htmlFor="edit-custom-role-name">
                        Role name <span className="text-[#b91c1c]">*</span>
                    </Label>
                    <Input
                        id="edit-custom-role-name"
                        value={name}
                        maxLength={context.roleNameMax + 10}
                        onChange={(event) => setName(event.target.value)}
                        aria-invalid={nameError !== null}
                        aria-describedby="edit-custom-role-name-help"
                    />
                    {nameError && (
                        <p
                            id="edit-custom-role-name-help"
                            className="text-[12px] text-[#b91c1c]"
                        >
                            {nameError}
                        </p>
                    )}
                </div>
                <ScopeChoice
                    name="edit-custom-role-scope"
                    scopes={context.scopes}
                    value={scope}
                    onChange={setScope}
                    disabledReason={
                        assigned
                            ? `Scope is fixed while ${role.assigned_count} staff account${role.assigned_count === 1 ? ' holds' : 's hold'} this role. Reassign them first, or create a new role.`
                            : null
                    }
                />
                {dropped.length > 0 && (
                    <p
                        role="note"
                        className="rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900"
                    >
                        The new scope cannot hold:{' '}
                        {dropped.map((key) => context.labels[key]).join(', ')}.
                        They are removed from the baseline when you save.
                    </p>
                )}
                {error && (
                    <p role="alert" className="text-[12.5px] text-red-700">
                        {error}
                    </p>
                )}
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        className={ownerSecondaryActionClass}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={saving || nameError !== null || scope === ''}
                        onClick={save}
                        className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-50`}
                    >
                        {saving && <Spinner />}
                        Save
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}

export function ArchiveCustomRoleDialog({
    role,
    open,
    onOpenChange,
}: {
    role: EditableCustomRole;
    open: boolean;
    onOpenChange: (open: boolean) => void;
}) {
    const [saving, setSaving] = useState(false);
    const [error, setError] = useState<string | null>(null);

    function archive() {
        setSaving(true);
        setError(null);
        router.post(
            archiveCustomRole.url(role.id),
            {},
            {
                preserveScroll: true,
                onError: (errors) => setError(firstError(errors)),
                onSuccess: () => onOpenChange(false),
                onFinish: () => setSaving(false),
            },
        );
    }

    return (
        <Dialog open={open} onOpenChange={onOpenChange}>
            <DialogContent className="owner-surface sm:max-w-md">
                <DialogHeader>
                    <DialogTitle>Archive {role.label}?</DialogTitle>
                    <DialogDescription>
                        The role can no longer be assigned. Its history stays in
                        the Audit Trail and its name becomes free for a new
                        role.
                    </DialogDescription>
                </DialogHeader>
                {error && (
                    <p role="alert" className="text-[12.5px] text-red-700">
                        {error}
                    </p>
                )}
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={() => onOpenChange(false)}
                        className={ownerSecondaryActionClass}
                    >
                        Cancel
                    </button>
                    <button
                        type="button"
                        disabled={saving}
                        onClick={archive}
                        className="inline-flex min-h-11 items-center justify-center gap-2 rounded-[10px] bg-red-700 px-4 text-[13px] font-semibold text-white hover:bg-red-800 focus-visible:ring-2 focus-visible:ring-red-700 focus-visible:ring-offset-2 focus-visible:outline-none disabled:opacity-50"
                    >
                        {saving && <Spinner />}
                        Archive role
                    </button>
                </div>
            </DialogContent>
        </Dialog>
    );
}
