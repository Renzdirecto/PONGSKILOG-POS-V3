import { useForm, usePage } from '@inertiajs/react';
import { AlertTriangle, Camera, Globe2, KeyRound, Lock } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    ownerControlClass,
    ownerPrimaryActionClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import PasswordInput from '@/components/password-input';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { staffChangeWarnings, type StaffRoleOption } from '@/lib/staff-admin';
import { update as ownerStaffUpdate } from '@/routes/staff';
import {
    password as resetPasswordRoute,
    update as superAdminStaffUpdate,
} from '@/routes/super-admin/staff';
import type { BranchSummary } from '@/types';

export type ManagedStaffMember = {
    id: number;
    employee_id: string | null;
    avatar_url: string | null;
    name: string;
    email: string;
    is_active: boolean;
    roles: { name: string; label: string }[];
    business_wide: boolean;
    branches: BranchSummary[];
    is_self: boolean;
    custom_access_count: number;
};

function FieldError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} role="alert" className="text-xs text-red-700">
            {message}
        </p>
    ) : null;
}

function currentQuery(url: string): Record<string, string> {
    return Object.fromEntries(new URL(url, 'http://localhost').searchParams);
}

/**
 * Edit an existing account. The Employee ID is shown read-only. High-impact changes (role, deactivation, removed
 * Branch access) are confirmed in plain words before saving; the server re-checks everything.
 */
export function EditStaffForm({
    member,
    roles,
    branches,
    surface,
    onSaved,
}: {
    member: ManagedStaffMember;
    roles: StaffRoleOption[];
    branches: BranchSummary[];
    surface: 'owner' | 'super_admin';
    onSaved: () => void;
}) {
    const page = usePage();
    const currentRole = member.roles.length === 1 ? member.roles[0].name : '';
    const form = useForm({
        name: member.name,
        email: member.email,
        role: currentRole,
        branch_ids: member.business_wide
            ? ([] as string[])
            : member.branches.map((branch) => branch.id),
        is_active: member.is_active,
        avatar: null as File | null,
        remove_avatar: false,
    });
    const [confirming, setConfirming] = useState(false);
    const submitting = useRef(false);
    const avatarInput = useRef<HTMLInputElement>(null);
    const previewUrl = useRef<string | null>(null);
    const [avatarPreview, setAvatarPreview] = useState<string | null>(null);
    const branchOptions = [
        ...branches,
        ...member.branches.filter(
            (assigned) => !branches.some((branch) => branch.id === assigned.id),
        ),
    ];
    const selectedRole = roles.find((role) => role.name === form.data.role);
    const requiresBranch =
        selectedRole !== undefined && !selectedRole.business_wide;
    const warnings = staffChangeWarnings({
        member: {
            role: currentRole,
            is_active: member.is_active,
            branch_ids: member.branches.map((branch) => branch.id),
            custom_access_count: member.custom_access_count,
            business_wide: member.business_wide,
        },
        next: {
            role: form.data.role,
            is_active: form.data.is_active,
            branch_ids: requiresBranch ? form.data.branch_ids : [],
        },
        roles,
        branchNames: Object.fromEntries(
            branchOptions.map((branch) => [branch.id, branch.name]),
        ),
    });
    const branchError =
        form.errors.branch_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('branch_ids.'),
        )?.[1];
    const shownAvatar = form.data.remove_avatar
        ? null
        : (avatarPreview ?? member.avatar_url);

    useEffect(
        () => () => {
            if (previewUrl.current) URL.revokeObjectURL(previewUrl.current);
        },
        [],
    );

    function chooseAvatar(file: File | null) {
        if (previewUrl.current) URL.revokeObjectURL(previewUrl.current);
        previewUrl.current = file ? URL.createObjectURL(file) : null;
        setAvatarPreview(previewUrl.current);
        form.setData((data) => ({
            ...data,
            avatar: file,
            remove_avatar: false,
        }));
        if (!file && avatarInput.current) avatarInput.current.value = '';
    }

    function submit() {
        if (submitting.current) return;
        submitting.current = true;
        const route =
            surface === 'owner' ? ownerStaffUpdate : superAdminStaffUpdate;
        form.transform((data) => {
            const { branch_ids: branchIds, avatar, ...rest } = data;

            return {
                ...rest,
                ...(requiresBranch ? { branch_ids: branchIds } : {}),
                ...(avatar ? { avatar } : {}),
                _method: 'put',
            };
        });
        form.post(route.url(member.id, { query: currentQuery(page.url) }), {
            preserveScroll: true,
            onSuccess: () => onSaved(),
            onError: (errors) => {
                setConfirming(false);
                const field = Object.keys(errors)[0]?.split('.')[0];
                document.getElementById(`edit-staff-${field}`)?.focus();
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (warnings.length > 0 && !confirming) {
            setConfirming(true);

            return;
        }
        submit();
    }

    function toggleBranch(branchId: string, checked: boolean) {
        form.setData(
            'branch_ids',
            checked
                ? [...form.data.branch_ids, branchId]
                : form.data.branch_ids.filter((id) => id !== branchId),
        );
    }

    if (confirming) {
        return (
            <div className="flex flex-col gap-4">
                <div
                    role="alert"
                    className="flex gap-2.5 rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12.5px] leading-5 text-amber-950"
                >
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    <div>
                        <p className="font-semibold">
                            Please confirm these changes to {member.name}:
                        </p>
                        <ul className="mt-1 list-disc space-y-1 pl-4">
                            {warnings.map((warning) => (
                                <li key={warning}>{warning}</li>
                            ))}
                        </ul>
                    </div>
                </div>
                <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                    <button
                        type="button"
                        onClick={() => setConfirming(false)}
                        disabled={form.processing}
                        className={ownerSecondaryActionClass}
                    >
                        Go back
                    </button>
                    <button
                        type="button"
                        onClick={submit}
                        disabled={form.processing}
                        className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2`}
                    >
                        {form.processing && <Spinner />}
                        Confirm and save
                    </button>
                </div>
            </div>
        );
    }

    return (
        <form
            onSubmit={save}
            className="flex flex-col gap-5"
            aria-busy={form.processing}
            noValidate
        >
            <fieldset
                className="flex flex-col gap-3"
                disabled={form.processing}
            >
                <legend className="mb-1 text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase">
                    Basic information
                </legend>
                <div className="flex items-center gap-3">
                    <span className="flex size-16 shrink-0 items-center justify-center overflow-hidden rounded-[14px] border border-[#e5e5e5] bg-[#f3f3f3] text-[#999]">
                        {shownAvatar ? (
                            <img
                                src={shownAvatar}
                                alt={`${member.name} profile picture`}
                                className="size-full object-cover"
                            />
                        ) : (
                            <Camera className="size-5" aria-hidden="true" />
                        )}
                    </span>
                    <div className="min-w-0 flex-1 space-y-1.5">
                        <div className="flex flex-wrap gap-2">
                            <label
                                htmlFor="edit-staff-avatar"
                                className={`${ownerSecondaryActionClass} inline-flex cursor-pointer items-center gap-1.5 has-focus-visible:ring-2`}
                            >
                                <Camera className="size-4" aria-hidden="true" />
                                {shownAvatar ? 'Replace photo' : 'Add photo'}
                                <input
                                    ref={avatarInput}
                                    id="edit-staff-avatar"
                                    type="file"
                                    accept="image/jpeg,image/png,image/webp"
                                    onChange={(event) =>
                                        chooseAvatar(
                                            event.target.files?.[0] ?? null,
                                        )
                                    }
                                    aria-describedby="edit-staff-avatar-hint edit-staff-avatar-error"
                                    className="sr-only"
                                />
                            </label>
                            {form.data.avatar ? (
                                <button
                                    type="button"
                                    onClick={() => chooseAvatar(null)}
                                    className={ownerSecondaryActionClass}
                                >
                                    Undo new photo
                                </button>
                            ) : member.avatar_url &&
                              !form.data.remove_avatar ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('remove_avatar', true)
                                    }
                                    aria-label="Remove profile picture"
                                    className={ownerSecondaryActionClass}
                                >
                                    Remove
                                </button>
                            ) : form.data.remove_avatar ? (
                                <button
                                    type="button"
                                    onClick={() =>
                                        form.setData('remove_avatar', false)
                                    }
                                    className={ownerSecondaryActionClass}
                                >
                                    Keep photo
                                </button>
                            ) : null}
                        </div>
                        <p
                            id="edit-staff-avatar-hint"
                            className="text-xs text-neutral-500"
                        >
                            {form.data.remove_avatar
                                ? 'The photo will be removed when you save.'
                                : 'Optional. JPG, PNG or WebP up to 2 MB.'}
                        </p>
                        <FieldError
                            id="edit-staff-avatar-error"
                            message={form.errors.avatar}
                        />
                    </div>
                </div>
                <div className="space-y-2">
                    <Label htmlFor="edit-staff-employee_id">Employee ID</Label>
                    <p
                        id="edit-staff-employee_id"
                        className={`${ownerControlClass} flex w-full items-center gap-2 bg-[#fafafa] font-mono text-[#555]`}
                    >
                        <Lock
                            className="size-3.5 text-[#999]"
                            aria-hidden="true"
                        />
                        {member.employee_id ?? 'No Employee ID'}
                    </p>
                    <p className="text-xs text-neutral-500">
                        The Employee ID is a permanent identity and cannot be
                        changed.
                    </p>
                </div>
                <div className="space-y-2">
                    <Label htmlFor="edit-staff-name">Full name</Label>
                    <Input
                        id="edit-staff-name"
                        autoComplete="off"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={255}
                        aria-invalid={!!form.errors.name}
                        aria-describedby="edit-staff-name-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <FieldError
                        id="edit-staff-name-error"
                        message={form.errors.name}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="edit-staff-email">Email</Label>
                    <Input
                        id="edit-staff-email"
                        type="email"
                        autoComplete="off"
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                        required
                        maxLength={255}
                        aria-invalid={!!form.errors.email}
                        aria-describedby="edit-staff-email-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <FieldError
                        id="edit-staff-email-error"
                        message={form.errors.email}
                    />
                </div>
            </fieldset>

            <fieldset
                className="flex flex-col gap-3"
                disabled={form.processing}
            >
                <legend className="mb-1 text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase">
                    Access
                </legend>
                {member.is_self && (
                    <p
                        role="note"
                        className="rounded-[11px] border border-[#e5e5e5] bg-[#fafafa] p-3 text-[12px] text-[#555]"
                    >
                        This is your own account. You cannot change your own
                        role or deactivate yourself.
                    </p>
                )}
                <div className="space-y-2">
                    <Label htmlFor="edit-staff-role">Role</Label>
                    <select
                        id="edit-staff-role"
                        value={form.data.role}
                        disabled={member.is_self}
                        onChange={(event) => {
                            const role = roles.find(
                                (item) => item.name === event.target.value,
                            );
                            form.setData((data) => ({
                                ...data,
                                role: event.target.value,
                                branch_ids: role?.business_wide
                                    ? []
                                    : data.branch_ids,
                            }));
                        }}
                        aria-invalid={!!form.errors.role}
                        aria-describedby="edit-staff-role-help edit-staff-role-error"
                        className={`${ownerControlClass} w-full`}
                    >
                        {currentRole === '' && (
                            <option value="" disabled>
                                Choose a role
                            </option>
                        )}
                        {roles.map((role) => (
                            <option key={role.name} value={role.name}>
                                {role.label}
                            </option>
                        ))}
                    </select>
                    <p
                        id="edit-staff-role-help"
                        className="text-xs text-neutral-500"
                    >
                        Changing the role resets this account&apos;s custom
                        access to the new role&apos;s baseline.
                    </p>
                    <FieldError
                        id="edit-staff-role-error"
                        message={form.errors.role}
                    />
                </div>

                <div className="space-y-2">
                    <span
                        id="edit-staff-branch-label"
                        className="text-sm leading-none font-medium"
                    >
                        Branch access
                    </span>
                    {selectedRole === undefined ? (
                        <p className="text-xs text-neutral-500">
                            Choose a role first.
                        </p>
                    ) : selectedRole.business_wide ? (
                        <p className="flex items-center gap-2 rounded-[11px] border border-[#e5e5e5] bg-[#fafafa] p-3 text-[12.5px] text-[#444]">
                            <Globe2
                                className="size-4 text-[#888]"
                                aria-hidden="true"
                            />
                            All branches / business-wide. Branch assignments are
                            cleared.
                        </p>
                    ) : branchOptions.length === 0 ? (
                        <p className="rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900">
                            No active Branches are available. Activate a Branch
                            in Settings first.
                        </p>
                    ) : (
                        <div
                            id="edit-staff-branch_ids"
                            role="group"
                            tabIndex={-1}
                            aria-labelledby="edit-staff-branch-label"
                            aria-describedby="edit-staff-branch-error"
                            className="grid gap-1.5 outline-none"
                        >
                            {branchOptions.map((branch) => (
                                <label
                                    key={branch.id}
                                    className="flex min-h-11 cursor-pointer items-center gap-3 rounded-[11px] border border-[#e5e5e5] px-3 text-[13px] has-checked:border-[#111]"
                                >
                                    <input
                                        type="checkbox"
                                        checked={form.data.branch_ids.includes(
                                            branch.id,
                                        )}
                                        onChange={(event) =>
                                            toggleBranch(
                                                branch.id,
                                                event.target.checked,
                                            )
                                        }
                                        className="size-4 accent-[#111]"
                                    />
                                    <span className="min-w-0 flex-1 truncate font-medium">
                                        {branch.name}
                                    </span>
                                    <span className="text-[11px] text-[#888]">
                                        {branch.code}
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                    <FieldError
                        id="edit-staff-branch-error"
                        message={branchError}
                    />
                </div>

                <div className="space-y-2">
                    <span
                        id="edit-staff-status-label"
                        className="text-sm leading-none font-medium"
                    >
                        Account status
                    </span>
                    <div
                        role="radiogroup"
                        aria-labelledby="edit-staff-status-label"
                        className="grid grid-cols-2 gap-2"
                    >
                        {[
                            { value: true, label: 'Active' },
                            { value: false, label: 'Inactive' },
                        ].map((option) => (
                            <label
                                key={option.label}
                                className={`flex min-h-11 items-center justify-center gap-2 rounded-[11px] border border-[#e5e5e5] text-[13px] font-semibold has-checked:border-[#111] has-checked:bg-[#111] has-checked:text-white has-focus-visible:ring-2 has-focus-visible:ring-[#111]/30 ${member.is_self ? 'cursor-not-allowed opacity-60' : 'cursor-pointer'}`}
                            >
                                <input
                                    type="radio"
                                    name="edit-staff-is_active"
                                    checked={
                                        form.data.is_active === option.value
                                    }
                                    disabled={member.is_self}
                                    onChange={() =>
                                        form.setData('is_active', option.value)
                                    }
                                    className="sr-only"
                                />
                                {option.label}
                            </label>
                        ))}
                    </div>
                    <p className="text-xs text-neutral-500">
                        Inactive accounts cannot sign in and are signed out of
                        open sessions. History is kept.
                    </p>
                    <FieldError
                        id="edit-staff-is_active-error"
                        message={form.errors.is_active}
                    />
                </div>
            </fieldset>

            <button
                type="submit"
                disabled={form.processing || !form.isDirty}
                className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-50`}
            >
                {form.processing && <Spinner />}
                {form.processing ? 'Saving…' : 'Save changes'}
            </button>
        </form>
    );
}

/**
 * Super Admin administrative reset: the Super Admin chooses a new temporary password, confirms it, and gives it to the
 * staff member directly. It is never shown again, and the account is signed out of other sessions.
 */
export function ResetStaffPasswordForm({
    member,
    onDone,
}: {
    member: ManagedStaffMember;
    onDone: () => void;
}) {
    const page = usePage();
    const form = useForm({ password: '', password_confirmation: '' });
    const [confirming, setConfirming] = useState(false);

    function submit(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (!confirming) {
            setConfirming(true);

            return;
        }
        form.put(
            resetPasswordRoute.url(member.id, {
                query: currentQuery(page.url),
            }),
            {
                preserveScroll: true,
                onSuccess: () => {
                    form.reset();
                    onDone();
                },
                onError: () => {
                    setConfirming(false);
                    form.reset();
                    document.getElementById('reset-staff-password')?.focus();
                },
            },
        );
    }

    return (
        <form onSubmit={submit} className="flex flex-col gap-4" noValidate>
            <fieldset
                className="flex flex-col gap-3"
                disabled={form.processing || confirming}
            >
                <div className="space-y-2">
                    <Label htmlFor="reset-staff-password">
                        New temporary password
                    </Label>
                    <PasswordInput
                        id="reset-staff-password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        required
                        aria-invalid={!!form.errors.password}
                        aria-describedby="reset-staff-password-hint reset-staff-password-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <p
                        id="reset-staff-password-hint"
                        className="text-xs text-neutral-500"
                    >
                        Give it to {member.name} directly. It is not shown again
                        after saving.
                    </p>
                    <FieldError
                        id="reset-staff-password-error"
                        message={form.errors.password}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="reset-staff-password_confirmation">
                        Confirm new temporary password
                    </Label>
                    <PasswordInput
                        id="reset-staff-password_confirmation"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'password_confirmation',
                                event.target.value,
                            )
                        }
                        required
                        className={`${ownerControlClass} w-full`}
                    />
                </div>
            </fieldset>
            {confirming && (
                <p
                    role="alert"
                    className="flex gap-2 rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12.5px] leading-5 text-amber-950"
                >
                    <AlertTriangle
                        className="mt-0.5 size-4 shrink-0"
                        aria-hidden="true"
                    />
                    {member.name} will be signed out everywhere and must use the
                    new password to sign in again.
                </p>
            )}
            <div className="flex flex-col-reverse gap-2 sm:flex-row sm:justify-end">
                {confirming && (
                    <button
                        type="button"
                        onClick={() => setConfirming(false)}
                        disabled={form.processing}
                        className={ownerSecondaryActionClass}
                    >
                        Go back
                    </button>
                )}
                <button
                    type="submit"
                    disabled={form.processing || form.data.password === ''}
                    className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-50`}
                >
                    {form.processing ? (
                        <Spinner />
                    ) : (
                        <KeyRound className="size-4" aria-hidden="true" />
                    )}
                    {confirming ? 'Confirm password reset' : 'Reset password'}
                </button>
            </div>
        </form>
    );
}
