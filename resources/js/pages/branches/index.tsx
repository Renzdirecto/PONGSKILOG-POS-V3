import { Head, Link, useForm } from '@inertiajs/react';
import { Building2, Pencil, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import type { FormEvent } from 'react';
import { Button } from '@/components/ui/button';
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
import { workspace } from '@/routes';
import { store, update } from '@/routes/branches';
import { show as showQr } from '@/routes/qr';
import type { BranchSummary } from '@/types';

type BranchStatus = 'active' | 'temporarily_closed' | 'inactive';
type Branch = BranchSummary & {
    status: BranchStatus;
    address: string | null;
    contact: string | null;
    store_is_open: boolean;
};

const statusLabels: Record<BranchStatus, string> = {
    active: 'Active',
    temporarily_closed: 'Temporarily closed',
    inactive: 'Inactive',
};
const actionClass =
    'min-h-11 rounded-xl border-neutral-200 bg-white text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950';

export default function Branches({ branches }: { branches: Branch[] }) {
    const [editing, setEditing] = useState<Branch | null | undefined>(
        undefined,
    );

    return (
        <>
            <Head title="Branch management" />
            <div className="mx-auto flex max-w-5xl flex-col gap-6">
                <Link
                    href={workspace()}
                    className="w-fit py-2 text-sm font-semibold underline underline-offset-4"
                >
                    Back to workspace
                </Link>
                <div className="flex flex-col gap-4 sm:flex-row sm:items-end sm:justify-between">
                    <div className="space-y-2">
                        <p className="text-xs font-bold tracking-[0.18em] text-[#8c671e] uppercase">
                            Business Operations
                        </p>
                        <h1 className="text-3xl font-bold tracking-tight">
                            Branch management
                        </h1>
                        <p className="text-sm leading-6 text-neutral-600">
                            Maintain branch details and view the current store
                            state.
                        </p>
                    </div>
                    <Button
                        className="min-h-11 rounded-xl bg-neutral-950 px-5 text-white hover:bg-neutral-800"
                        onClick={() => setEditing(null)}
                    >
                        <Plus /> Add Branch
                    </Button>
                </div>
                {branches.length === 0 ? (
                    <div className="rounded-2xl border border-neutral-200 bg-white p-8 text-center">
                        <Building2 className="mx-auto mb-3 size-8 text-neutral-400" />
                        <h2 className="text-lg font-bold">No branches yet</h2>
                        <p className="mt-2 text-sm text-neutral-600">
                            Add your first branch to get started.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-4 md:grid-cols-2">
                        {branches.map((branch) => (
                            <li
                                key={branch.id}
                                className="flex min-w-0 flex-col gap-5 rounded-2xl border border-neutral-200 bg-white p-5 shadow-sm sm:p-6"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <span className="text-xs font-bold tracking-wider text-neutral-500">
                                        {branch.code}
                                    </span>
                                    <span className="rounded-full bg-neutral-100 px-3 py-1.5 text-xs font-semibold">
                                        {statusLabels[branch.status]}
                                    </span>
                                </div>
                                <h2 className="text-xl font-bold wrap-break-word">
                                    {branch.name}
                                </h2>
                                <dl className="grid gap-3 text-sm">
                                    <div>
                                        <dt className="text-xs text-neutral-500">
                                            Address
                                        </dt>
                                        <dd className="mt-1 wrap-break-word whitespace-pre-line">
                                            {branch.address ||
                                                'No address added'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-xs text-neutral-500">
                                            Contact
                                        </dt>
                                        <dd className="mt-1 wrap-break-word">
                                            {branch.contact ||
                                                'No contact added'}
                                        </dd>
                                    </div>
                                </dl>
                                <div className="mt-auto flex flex-wrap items-center gap-3 border-t border-neutral-100 pt-4">
                                    <span
                                        className={`mr-auto text-xs font-bold ${branch.store_is_open ? 'text-emerald-700' : 'text-neutral-500'}`}
                                    >
                                        STORE{' '}
                                        {branch.store_is_open
                                            ? 'OPEN'
                                            : 'CLOSED'}
                                    </span>
                                    <Button
                                        variant="outline"
                                        className={actionClass}
                                        onClick={() => setEditing(branch)}
                                        aria-label={`Edit ${branch.name}`}
                                    >
                                        <Pencil /> Edit
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className={actionClass}
                                        asChild
                                    >
                                        <Link href={showQr(branch.id)}>
                                            View QR page
                                        </Link>
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-xs leading-5 text-neutral-500">
                    Store state reflects the current Store Session. Changing
                    branch status does not open or close a session.
                </p>
            </div>
            <Dialog
                open={editing !== undefined}
                onOpenChange={(open) => {
                    if (!open) setEditing(undefined);
                }}
            >
                <DialogContent className="max-h-[90svh] overflow-y-auto border-neutral-200 bg-white text-neutral-950 sm:max-w-lg">
                    <DialogHeader>
                        <DialogTitle>
                            {editing ? 'Edit branch' : 'Add branch'}
                        </DialogTitle>
                        <DialogDescription className="text-neutral-600">
                            Update core branch information. Inactive and
                            temporarily closed branches are unavailable to
                            customers.
                        </DialogDescription>
                    </DialogHeader>
                    {editing !== undefined && (
                        <BranchForm
                            key={editing?.id ?? 'new'}
                            branch={editing}
                            onSaved={() => setEditing(undefined)}
                        />
                    )}
                </DialogContent>
            </Dialog>
        </>
    );
}

function BranchForm({
    branch,
    onSaved,
}: {
    branch: Branch | null;
    onSaved: () => void;
}) {
    const form = useForm({
        code: branch?.code ?? '',
        name: branch?.name ?? '',
        status: branch?.status ?? 'active',
        address: branch?.address ?? '',
        contact: branch?.contact ?? '',
    });
    const submitting = useRef(false);

    function save(event: FormEvent<HTMLFormElement>) {
        event.preventDefault();
        if (submitting.current) return;
        submitting.current = true;
        form.submit(branch ? update(branch.id) : store(), {
            preserveScroll: true,
            onSuccess: onSaved,
            onError: (errors) =>
                document
                    .getElementById(`branch-${Object.keys(errors)[0]}`)
                    ?.focus(),
            onFinish: () => {
                submitting.current = false;
            },
        });
    }

    return (
        <form
            onSubmit={save}
            className="flex flex-col gap-4"
            aria-busy={form.processing}
        >
            {(['code', 'name', 'address', 'contact'] as const).map((field) => (
                <div key={field} className="space-y-2">
                    <Label htmlFor={`branch-${field}`}>
                        {field.charAt(0).toUpperCase() + field.slice(1)}
                        {(field === 'address' || field === 'contact') &&
                            ' (optional)'}
                    </Label>
                    <Input
                        id={`branch-${field}`}
                        name={field}
                        value={form.data[field]}
                        onChange={(event) =>
                            form.setData(field, event.target.value)
                        }
                        required={field === 'code' || field === 'name'}
                        maxLength={
                            { code: 32, name: 150, address: 500, contact: 100 }[
                                field
                            ]
                        }
                        disabled={form.processing}
                        aria-invalid={!!form.errors[field]}
                        aria-describedby={`branch-${field}-hint`}
                        className="h-11 border-neutral-300 bg-white text-base dark:bg-white"
                    />
                    <p
                        id={`branch-${field}-hint`}
                        className={`text-xs ${form.errors[field] ? 'text-red-700' : 'text-neutral-500'}`}
                        role={form.errors[field] ? 'alert' : undefined}
                    >
                        {form.errors[field] ||
                            (field === 'code'
                                ? 'Uppercase letters, numbers, underscores and hyphens. Start with a letter or number.'
                                : '')}
                    </p>
                </div>
            ))}
            <div className="space-y-2">
                <Label htmlFor="branch-status">Status</Label>
                <select
                    id="branch-status"
                    name="status"
                    value={form.data.status}
                    onChange={(event) =>
                        form.setData(
                            'status',
                            event.target.value as BranchStatus,
                        )
                    }
                    disabled={form.processing}
                    aria-invalid={!!form.errors.status}
                    aria-describedby="branch-status-error"
                    className="h-11 w-full rounded-md border border-neutral-300 bg-white px-3 text-base focus-visible:ring-2 focus-visible:ring-neutral-950 focus-visible:outline-none"
                >
                    {Object.entries(statusLabels).map(([value, label]) => (
                        <option key={value} value={value}>
                            {label}
                        </option>
                    ))}
                </select>
                {form.errors.status && (
                    <p
                        id="branch-status-error"
                        role="alert"
                        className="text-xs text-red-700"
                    >
                        {form.errors.status}
                    </p>
                )}
            </div>
            <Button
                type="submit"
                disabled={form.processing}
                className="mt-2 min-h-11 rounded-xl bg-neutral-950 text-white hover:bg-neutral-800"
            >
                {form.processing && <Spinner />}
                {form.processing
                    ? 'Saving…'
                    : branch
                      ? 'Save changes'
                      : 'Add Branch'}
            </Button>
        </form>
    );
}
