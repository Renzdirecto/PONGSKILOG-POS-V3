import { Head, Link, router, useForm } from '@inertiajs/react';
import {
    ChevronLeft,
    ChevronRight,
    Globe2,
    Search,
    ShieldAlert,
    UserPlus,
    Users,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent } from 'react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerControlClass,
    ownerPanelClass,
    ownerPrimaryActionClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import PasswordInput from '@/components/password-input';
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
import { index as staffIndex, store } from '@/routes/super-admin/staff';
import type { BranchSummary } from '@/types';

type StaffRole = { name: string; label: string; business_wide: boolean };
type StaffMember = {
    id: number;
    employee_id: string | null;
    name: string;
    email: string;
    is_active: boolean;
    roles: { name: string; label: string }[];
    business_wide: boolean;
    branches: BranchSummary[];
    created_at: string | null;
};
type Filters = { search?: string; role?: string; status?: string };
type Props = {
    staff: {
        data: StaffMember[];
        from: number | null;
        to: number | null;
        total: number;
        prev_page_url: string | null;
        next_page_url: string | null;
    };
    filters: Filters;
    roles: StaffRole[];
    branches: BranchSummary[];
};

const selectClass = `${ownerControlClass} w-full`;

function applyFilters(filters: Filters): void {
    router.get(
        staffIndex.url(),
        Object.fromEntries(
            Object.entries(filters).filter(([, value]) => value),
        ),
        {
            preserveState: true,
            preserveScroll: true,
            replace: true,
            only: ['staff', 'filters'],
        },
    );
}

function BranchAccess({ member }: { member: StaffMember }) {
    if (member.business_wide) {
        return (
            <span className="inline-flex items-center gap-1.5 text-[12px] text-[#444]">
                <Globe2 className="size-3.5 text-[#888]" aria-hidden="true" />
                All branches / business-wide
            </span>
        );
    }

    if (member.branches.length === 0) {
        return (
            <span className="text-[12px] text-[#a15c00]">No active Branch</span>
        );
    }

    return (
        <span className="text-[12px] text-[#444]">
            {member.branches.map((branch) => branch.name).join(', ')}
        </span>
    );
}

function StatusBadge({ active }: { active: boolean }) {
    return (
        <OwnerStatusBadge tone={active ? 'green' : 'neutral'}>
            {active ? 'Active' : 'Inactive'}
        </OwnerStatusBadge>
    );
}

export default function Staff({ staff, filters, roles, branches }: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [adding, setAdding] = useState(false);
    const searchTimer = useRef<number | undefined>(undefined);
    const hasFilters = Boolean(
        filters.search || filters.role || filters.status,
    );

    useEffect(() => () => window.clearTimeout(searchTimer.current), []);

    /** Typed search is debounced; role and status selects apply immediately. */
    function changeSearch(value: string) {
        setSearch(value);
        window.clearTimeout(searchTimer.current);
        searchTimer.current = window.setTimeout(
            () => applyFilters({ ...filters, search: value }),
            350,
        );
    }

    return (
        <>
            <Head title="Staff" />
            <OwnerPage
                title="Staff"
                description="Create and manage staff access to PONGSKILOG."
                action={
                    <button
                        type="button"
                        onClick={() => setAdding(true)}
                        className={`${ownerPrimaryActionClass} inline-flex w-full items-center justify-center gap-2 md:w-auto`}
                    >
                        <UserPlus className="size-4" aria-hidden="true" />
                        Add Staff
                    </button>
                }
                maxWidth="max-w-[1180px]"
            >
                <section
                    aria-label="Filter staff"
                    className={`${ownerPanelClass} grid gap-2 p-3 sm:grid-cols-[minmax(0,1fr)_180px_160px]`}
                >
                    <label className="relative block">
                        <span className="sr-only">
                            Search by name, email, or Employee ID
                        </span>
                        <Search
                            className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-[#999]"
                            aria-hidden="true"
                        />
                        <input
                            type="search"
                            value={search}
                            onChange={(event) =>
                                changeSearch(event.target.value)
                            }
                            placeholder="Search name, email, or ID"
                            maxLength={150}
                            className={`${ownerControlClass} w-full pl-9`}
                        />
                    </label>
                    <label className="block">
                        <span className="sr-only">Filter by role</span>
                        <select
                            value={filters.role ?? ''}
                            onChange={(event) =>
                                applyFilters({
                                    ...filters,
                                    search,
                                    role: event.target.value,
                                })
                            }
                            className={selectClass}
                        >
                            <option value="">All roles</option>
                            {roles.map((role) => (
                                <option key={role.name} value={role.name}>
                                    {role.label}
                                </option>
                            ))}
                        </select>
                    </label>
                    <label className="block">
                        <span className="sr-only">Filter by status</span>
                        <select
                            value={filters.status ?? ''}
                            onChange={(event) =>
                                applyFilters({
                                    ...filters,
                                    search,
                                    status: event.target.value,
                                })
                            }
                            className={selectClass}
                        >
                            <option value="">All statuses</option>
                            <option value="active">Active</option>
                            <option value="inactive">Inactive</option>
                        </select>
                    </label>
                </section>

                {staff.data.length === 0 ? (
                    <div
                        className={`${ownerPanelClass} px-5 py-12 text-center`}
                    >
                        <Users
                            className="mx-auto size-7 text-[#aaa]"
                            aria-hidden="true"
                        />
                        <h2 className="mt-3 text-sm font-semibold">
                            {hasFilters
                                ? 'No staff match these filters'
                                : 'No staff accounts yet'}
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[#767676]">
                            {hasFilters
                                ? 'Try a different name, email, Employee ID, role, or status.'
                                : 'Add a staff account to give someone access.'}
                        </p>
                        {hasFilters && (
                            <button
                                type="button"
                                onClick={() => {
                                    window.clearTimeout(searchTimer.current);
                                    setSearch('');
                                    applyFilters({});
                                }}
                                className={`${ownerSecondaryActionClass} mt-4 inline-flex items-center gap-1.5`}
                            >
                                <X className="size-3.5" aria-hidden="true" />
                                Clear filters
                            </button>
                        )}
                    </div>
                ) : (
                    <section
                        aria-label="Staff accounts"
                        className={`${ownerPanelClass} overflow-hidden`}
                    >
                        <table className="hidden w-full table-fixed text-left md:table">
                            <thead className="border-b border-[#eeeeee] bg-[#fafafa] text-[10px] font-semibold tracking-[0.06em] text-[#888] uppercase">
                                <tr>
                                    <th
                                        scope="col"
                                        className="w-[110px] px-4 py-3"
                                    >
                                        Employee ID
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-[20%] px-4 py-3"
                                    >
                                        Name
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-[24%] px-4 py-3"
                                    >
                                        Email
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-[16%] px-4 py-3"
                                    >
                                        Role
                                    </th>
                                    <th scope="col" className="px-4 py-3">
                                        Branch access
                                    </th>
                                    <th
                                        scope="col"
                                        className="w-[110px] px-4 py-3"
                                    >
                                        Status
                                    </th>
                                </tr>
                            </thead>
                            <tbody className="divide-y divide-[#f0f0f0]">
                                {staff.data.map((member) => (
                                    <tr key={member.id} className="align-top">
                                        <td className="px-4 py-3 font-mono text-[12.5px] text-[#444]">
                                            {member.employee_id ?? '—'}
                                        </td>
                                        <td className="px-4 py-3 text-[13px] font-semibold wrap-break-word">
                                            {member.name}
                                        </td>
                                        <td className="px-4 py-3 text-[12.5px] break-all text-[#444]">
                                            {member.email}
                                        </td>
                                        <td className="px-4 py-3 text-[12.5px]">
                                            {member.roles
                                                .map((role) => role.label)
                                                .join(', ') || 'No role'}
                                        </td>
                                        <td className="px-4 py-3">
                                            <BranchAccess member={member} />
                                        </td>
                                        <td className="px-4 py-3">
                                            <StatusBadge
                                                active={member.is_active}
                                            />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                        <ul className="divide-y divide-[#f0f0f0] md:hidden">
                            {staff.data.map((member) => (
                                <li
                                    key={member.id}
                                    className="flex flex-col gap-1.5 p-4"
                                >
                                    <div className="flex items-start justify-between gap-3">
                                        <div className="min-w-0">
                                            <p className="text-[13.5px] font-semibold wrap-break-word">
                                                {member.name}
                                            </p>
                                            <p className="text-[12px] break-all text-[#666]">
                                                {member.email}
                                            </p>
                                            <p className="font-mono text-[11.5px] text-[#888]">
                                                ID {member.employee_id ?? '—'}
                                            </p>
                                        </div>
                                        <StatusBadge
                                            active={member.is_active}
                                        />
                                    </div>
                                    <p className="text-[12px] font-medium">
                                        {member.roles
                                            .map((role) => role.label)
                                            .join(', ') || 'No role'}
                                    </p>
                                    <BranchAccess member={member} />
                                </li>
                            ))}
                        </ul>
                        <nav
                            aria-label="Staff pages"
                            className="flex flex-wrap items-center justify-between gap-2 border-t border-[#eeeeee] px-4 py-3 text-[12px] text-[#767676]"
                        >
                            <span>
                                {staff.from}–{staff.to} of {staff.total}
                            </span>
                            <span className="flex gap-2">
                                {staff.prev_page_url ? (
                                    <Link
                                        href={staff.prev_page_url}
                                        preserveScroll
                                        preserveState
                                        className={`${ownerSecondaryActionClass} inline-flex items-center gap-1`}
                                    >
                                        <ChevronLeft className="size-4" />
                                        Previous
                                    </Link>
                                ) : null}
                                {staff.next_page_url ? (
                                    <Link
                                        href={staff.next_page_url}
                                        preserveScroll
                                        preserveState
                                        className={`${ownerSecondaryActionClass} inline-flex items-center gap-1`}
                                    >
                                        Next
                                        <ChevronRight className="size-4" />
                                    </Link>
                                ) : null}
                            </span>
                        </nav>
                    </section>
                )}
            </OwnerPage>

            <Dialog open={adding} onOpenChange={setAdding}>
                <DialogContent className="owner-surface top-auto bottom-0 max-h-[92dvh] w-full max-w-none translate-y-0 overflow-y-auto rounded-t-[20px] rounded-b-none border-[#e5e5e5] bg-white text-neutral-950 sm:top-1/2 sm:bottom-auto sm:max-w-lg sm:-translate-y-1/2 sm:rounded-[18px] [&>button]:top-2 [&>button]:right-2 [&>button]:flex [&>button]:size-11 [&>button]:items-center [&>button]:justify-center">
                    <DialogHeader>
                        <DialogTitle className="text-[16px] font-semibold">
                            Add Staff
                        </DialogTitle>
                        <DialogDescription className="text-[12.5px] text-neutral-600">
                            Create a login account. Share the temporary password
                            with the staff member directly.
                        </DialogDescription>
                    </DialogHeader>
                    {adding && (
                        <AddStaffForm
                            roles={roles}
                            branches={branches}
                            onCreated={() => setAdding(false)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function FieldError({ id, message }: { id: string; message?: string }) {
    return message ? (
        <p id={id} role="alert" className="text-xs text-red-700">
            {message}
        </p>
    ) : null;
}

function AddStaffForm({
    roles,
    branches,
    onCreated,
}: {
    roles: StaffRole[];
    branches: BranchSummary[];
    onCreated: () => void;
}) {
    const form = useForm({
        employee_id: '',
        name: '',
        email: '',
        password: '',
        password_confirmation: '',
        role: '',
        branch_ids: [] as string[],
        is_active: true,
    });
    const submitting = useRef(false);
    const selectedRole = roles.find((role) => role.name === form.data.role);
    const requiresBranch =
        selectedRole !== undefined && !selectedRole.business_wide;
    const branchError =
        form.errors.branch_ids ??
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('branch_ids.'),
        )?.[1];

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting.current) return;
        submitting.current = true;
        form.transform((data) => {
            const { branch_ids: branchIds, ...rest } = data;

            return requiresBranch ? { ...rest, branch_ids: branchIds } : rest;
        });
        form.submit(store(), {
            preserveScroll: true,
            onSuccess: () => {
                form.reset();
                onCreated();
            },
            onError: (errors) => {
                form.reset('password', 'password_confirmation');
                const field = Object.keys(errors)[0]?.split('.')[0];
                document.getElementById(`staff-${field}`)?.focus();
            },
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    function toggleBranch(branchId: string, checked: boolean) {
        form.setData(
            'branch_ids',
            checked
                ? [...form.data.branch_ids, branchId]
                : form.data.branch_ids.filter((id) => id !== branchId),
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
                <div className="space-y-2">
                    <Label htmlFor="staff-employee_id">Employee ID</Label>
                    <Input
                        id="staff-employee_id"
                        name="employee_id"
                        autoComplete="off"
                        inputMode="numeric"
                        placeholder="MMDDYY01"
                        value={form.data.employee_id}
                        onChange={(event) =>
                            form.setData('employee_id', event.target.value)
                        }
                        required
                        maxLength={8}
                        aria-invalid={!!form.errors.employee_id}
                        aria-describedby="staff-employee_id-hint staff-employee_id-error"
                        className={`${ownerControlClass} w-full font-mono`}
                    />
                    <p
                        id="staff-employee_id-hint"
                        className="text-xs text-neutral-500"
                    >
                        MMDDYY followed by a two-digit number, for example
                        09242601.
                    </p>
                    <FieldError
                        id="staff-employee_id-error"
                        message={form.errors.employee_id}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="staff-name">Full name</Label>
                    <Input
                        id="staff-name"
                        name="name"
                        autoComplete="off"
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                        required
                        maxLength={255}
                        aria-invalid={!!form.errors.name}
                        aria-describedby="staff-name-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <FieldError
                        id="staff-name-error"
                        message={form.errors.name}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="staff-email">Email</Label>
                    <Input
                        id="staff-email"
                        name="email"
                        type="email"
                        autoComplete="off"
                        value={form.data.email}
                        onChange={(event) =>
                            form.setData('email', event.target.value)
                        }
                        required
                        maxLength={255}
                        aria-invalid={!!form.errors.email}
                        aria-describedby="staff-email-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <FieldError
                        id="staff-email-error"
                        message={form.errors.email}
                    />
                </div>
            </fieldset>

            <fieldset
                className="flex flex-col gap-3"
                disabled={form.processing}
            >
                <legend className="mb-1 text-[10px] font-semibold tracking-[0.08em] text-[#888] uppercase">
                    Account
                </legend>
                <div className="space-y-2">
                    <Label htmlFor="staff-password">Temporary password</Label>
                    <PasswordInput
                        id="staff-password"
                        name="password"
                        autoComplete="new-password"
                        value={form.data.password}
                        onChange={(event) =>
                            form.setData('password', event.target.value)
                        }
                        required
                        aria-invalid={!!form.errors.password}
                        aria-describedby="staff-password-hint staff-password-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <p
                        id="staff-password-hint"
                        className="text-xs text-neutral-500"
                    >
                        You choose this password and give it to the staff
                        member. It is not shown again after creation.
                    </p>
                    <FieldError
                        id="staff-password-error"
                        message={form.errors.password}
                    />
                </div>
                <div className="space-y-2">
                    <Label htmlFor="staff-password_confirmation">
                        Confirm temporary password
                    </Label>
                    <PasswordInput
                        id="staff-password_confirmation"
                        name="password_confirmation"
                        autoComplete="new-password"
                        value={form.data.password_confirmation}
                        onChange={(event) =>
                            form.setData(
                                'password_confirmation',
                                event.target.value,
                            )
                        }
                        required
                        aria-invalid={!!form.errors.password_confirmation}
                        aria-describedby="staff-password_confirmation-error"
                        className={`${ownerControlClass} w-full`}
                    />
                    <FieldError
                        id="staff-password_confirmation-error"
                        message={form.errors.password_confirmation}
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
                <div className="space-y-2">
                    <Label htmlFor="staff-role">Role</Label>
                    <select
                        id="staff-role"
                        name="role"
                        value={form.data.role}
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
                        required
                        aria-invalid={!!form.errors.role}
                        aria-describedby="staff-role-error"
                        className={selectClass}
                    >
                        <option value="" disabled>
                            Choose a role
                        </option>
                        {roles.map((role) => (
                            <option key={role.name} value={role.name}>
                                {role.label}
                            </option>
                        ))}
                    </select>
                    <FieldError
                        id="staff-role-error"
                        message={form.errors.role}
                    />
                </div>

                {selectedRole?.name === 'super_admin' && (
                    <div
                        role="note"
                        className="flex gap-2.5 rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12px] leading-5 text-amber-900"
                    >
                        <ShieldAlert
                            className="mt-0.5 size-4 shrink-0"
                            aria-hidden="true"
                        />
                        <span>
                            <strong className="font-semibold">
                                Full access.
                            </strong>{' '}
                            A Super Admin can open every workspace, create staff
                            accounts, review audit and void records, and change
                            business settings.
                        </span>
                    </div>
                )}

                <div className="space-y-2">
                    <span
                        id="staff-branch-label"
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
                            All branches / business-wide
                        </p>
                    ) : branches.length === 0 ? (
                        <p className="rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12px] text-amber-900">
                            No active Branches are available. Activate a Branch
                            in Settings first.
                        </p>
                    ) : (
                        <div
                            id="staff-branch_ids"
                            role="group"
                            tabIndex={-1}
                            aria-labelledby="staff-branch-label"
                            aria-describedby="staff-branch-error"
                            className="grid gap-1.5 outline-none"
                        >
                            {branches.map((branch) => (
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
                    <FieldError id="staff-branch-error" message={branchError} />
                </div>

                <div className="space-y-2">
                    <span
                        id="staff-status-label"
                        className="text-sm leading-none font-medium"
                    >
                        Account status
                    </span>
                    <div
                        role="radiogroup"
                        aria-labelledby="staff-status-label"
                        className="grid grid-cols-2 gap-2"
                    >
                        {[
                            { value: true, label: 'Active' },
                            { value: false, label: 'Inactive' },
                        ].map((option) => (
                            <label
                                key={option.label}
                                className="flex min-h-11 cursor-pointer items-center justify-center gap-2 rounded-[11px] border border-[#e5e5e5] text-[13px] font-semibold has-checked:border-[#111] has-checked:bg-[#111] has-checked:text-white has-focus-visible:ring-2 has-focus-visible:ring-[#111]/30"
                            >
                                <input
                                    type="radio"
                                    name="is_active"
                                    checked={
                                        form.data.is_active === option.value
                                    }
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
                        Inactive accounts cannot sign in.
                    </p>
                    <FieldError
                        id="staff-is_active-error"
                        message={form.errors.is_active}
                    />
                </div>
            </fieldset>

            <button
                type="submit"
                disabled={form.processing}
                className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2`}
            >
                {form.processing && <Spinner />}
                {form.processing ? 'Creating…' : 'Create Staff'}
            </button>
        </form>
    );
}
