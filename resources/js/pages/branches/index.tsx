import { Head, useForm } from '@inertiajs/react';
import { Building2, Pencil, Plus, QrCode } from 'lucide-react';
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
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerPanelClass,
} from '@/components/owner-ui';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { store, update } from '@/routes/branches';
import { toast } from 'sonner';
import type { BranchSummary } from '@/types';

type BranchStatus = 'active' | 'temporarily_closed' | 'inactive';
type Branch = BranchSummary & {
    status: BranchStatus;
    address: string | null;
    contact: string | null;
    store_is_open: boolean;
    qr_url: string;
    qr_image: string;
};

const statusLabels: Record<BranchStatus, string> = {
    active: 'Active',
    temporarily_closed: 'Temporarily closed',
    inactive: 'Inactive',
};
export default function Branches({ branches }: { branches: Branch[] }) {
    const [qrBranch, setQrBranch] = useState<Branch | null>(null);
    const [editing, setEditing] = useState<Branch | null | undefined>(
        undefined,
    );

    return (
        <>
            <Head title="Branch management" />
            <OwnerPage
                title="Branch management"
                description="Maintain branch details, availability, customer QR entry points, and current store state."
                action={
                    <Button
                        className={`${primaryActionClass} w-full md:w-auto`}
                        onClick={() => setEditing(null)}
                    >
                        <Plus className="size-4" /> Add branch
                    </Button>
                }
                maxWidth="max-w-[1180px]"
            >
                {branches.length === 0 ? (
                    <div
                        className={`${ownerPanelClass} px-5 py-14 text-center`}
                    >
                        <Building2 className="mx-auto size-7 text-[#aaa]" />
                        <h2 className="mt-3 text-sm font-semibold">
                            No branches yet
                        </h2>
                        <p className="mt-1 text-[12.5px] text-[#767676]">
                            Add your first branch to get started.
                        </p>
                    </div>
                ) : (
                    <ul className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        {branches.map((branch) => (
                            <li
                                key={branch.id}
                                className={`${ownerPanelClass} flex min-w-0 flex-col gap-3 p-4`}
                            >
                                <div className="flex flex-wrap items-center justify-between gap-3">
                                    <span className="text-[10px] font-semibold tracking-[0.08em] text-[#767676] uppercase">
                                        {branch.code}
                                    </span>
                                    <OwnerStatusBadge
                                        tone={
                                            branch.status === 'active'
                                                ? 'green'
                                                : branch.status ===
                                                    'temporarily_closed'
                                                  ? 'amber'
                                                  : 'outline'
                                        }
                                    >
                                        {statusLabels[branch.status]}
                                    </OwnerStatusBadge>
                                </div>
                                <h2 className="text-[15px] font-semibold wrap-break-word">
                                    {branch.name}
                                </h2>
                                <dl className="grid gap-2 text-[12px]">
                                    <div>
                                        <dt className="text-[10px] font-semibold tracking-[0.05em] text-[#888] uppercase">
                                            Address
                                        </dt>
                                        <dd className="mt-1 wrap-break-word whitespace-pre-line">
                                            {branch.address ||
                                                'No address added'}
                                        </dd>
                                    </div>
                                    <div>
                                        <dt className="text-[10px] font-semibold tracking-[0.05em] text-[#888] uppercase">
                                            Contact
                                        </dt>
                                        <dd className="mt-1 wrap-break-word">
                                            {branch.contact ||
                                                'No contact added'}
                                        </dd>
                                    </div>
                                </dl>
                                <div className="mt-auto flex flex-wrap items-center gap-2 border-t border-[#eeeeee] pt-3">
                                    <OwnerStatusBadge
                                        tone={
                                            branch.store_is_open
                                                ? 'green'
                                                : 'neutral'
                                        }
                                    >
                                        Store{' '}
                                        {branch.store_is_open
                                            ? 'open'
                                            : 'closed'}
                                    </OwnerStatusBadge>
                                    <Button
                                        variant="outline"
                                        className={actionClass}
                                        onClick={() => setEditing(branch)}
                                        aria-label={`Edit ${branch.name}`}
                                    >
                                        <Pencil className="size-3.5" /> Edit
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className={actionClass}
                                        onClick={() => setQrBranch(branch)}
                                    >
                                        <QrCode className="size-3.5" /> View QR
                                    </Button>
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
                <p className="text-[11.5px] leading-5 text-[#767676]">
                    Store state reflects the current Store Session. Changing
                    branch status does not open or close a session.
                </p>
            </OwnerPage>
            <Dialog
                open={qrBranch !== null}
                onOpenChange={(open) => {
                    if (!open) setQrBranch(null);
                }}
            >
                <DialogContent className="owner-surface max-h-[90dvh] overflow-y-auto rounded-[18px] bg-white text-neutral-950">
                    <DialogTitle>Customer QR</DialogTitle>
                    <DialogDescription>
                        Pongskilog ? {qrBranch?.name}
                    </DialogDescription>
                    {qrBranch && (
                        <div className="flex flex-col items-center gap-4">
                            <img
                                src={qrBranch.qr_image}
                                alt={`Scan to order at ${qrBranch.name}`}
                                className="size-60 min-[520px]:size-[280px]"
                            />
                            <p className="text-sm font-semibold">
                                Scan to order
                            </p>
                            <a
                                className="max-w-full text-center text-xs wrap-anywhere underline"
                                href={qrBranch.qr_url}
                                target="_blank"
                                rel="noreferrer"
                            >
                                {qrBranch.qr_url}
                            </a>
                            <Button
                                className={primaryActionClass}
                                onClick={async () => {
                                    try {
                                        await navigator.clipboard.writeText(
                                            qrBranch.qr_url,
                                        );
                                        toast.success('Ordering link copied');
                                    } catch {
                                        toast.error(
                                            'Copy blocked by the browser. Select and copy the link above.',
                                        );
                                    }
                                }}
                            >
                                Copy link
                            </Button>
                        </div>
                    )}
                </DialogContent>
            </Dialog>
            <Dialog
                open={editing !== undefined}
                onOpenChange={(open) => {
                    if (!open) setEditing(undefined);
                }}
            >
                <DialogContent className="owner-surface top-auto bottom-0 max-h-[92dvh] w-full max-w-none translate-y-0 overflow-y-auto rounded-t-[20px] rounded-b-none border-[#e5e5e5] bg-white text-neutral-950 sm:top-1/2 sm:bottom-auto sm:max-w-lg sm:-translate-y-1/2 sm:rounded-[18px]">
                    <DialogHeader>
                        <DialogTitle className="text-[16px] font-semibold">
                            {editing ? 'Edit branch' : 'Add branch'}
                        </DialogTitle>
                        <DialogDescription className="text-[12.5px] text-neutral-600">
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
                        className={controlClass}
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
                    className={`${controlClass} w-full`}
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
                className={`${primaryActionClass} mt-2`}
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
