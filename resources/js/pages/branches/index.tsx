import { update as saveQrSettings } from '@/routes/branches/qr-settings';
import {
    destroy as removeBranchImage,
    store as uploadBranchImage,
} from '@/routes/branches/image';
import { qrHistory } from '@/routes/branches';
import { qrRequest, qrError } from '@/lib/qr-http';
import { Head, useForm, router } from '@inertiajs/react';
import {
    Building2,
    Pencil,
    Plus,
    QrCode,
    Copy,
    ImagePlus,
    Trash2,
} from 'lucide-react';
import { useRef, useState, useEffect } from 'react';
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
import { CustomerScreenMediaPanel } from '@/components/customer-screen-media-panel';
import { CustomerScreenSettingsPanel } from '@/components/customer-screen-settings-panel';
import {
    ReceiptSettings,
    SettingSwitch,
} from '@/components/receipt-settings';
import { SegmentedTabs } from '@/components/owner-analytics';
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
import type { ReceiptLayoutSettings } from '@/types/receipt';

type BranchStatus = 'active' | 'temporarily_closed' | 'inactive';
type Branch = BranchSummary & {
    status: BranchStatus;
    address: string | null;
    contact: string | null;
    store_is_open: boolean;
    qr_url: string;
    qr_image: string;
    qr_ordering_enabled: boolean;
    receipt_name: string | null;
    receipt_address: string | null;
    receipt_contact: string | null;
    receipt_footer: string | null;
    receipt_show_logo: boolean;
    receipt_logo_url: string;
    receipt_layout: ReceiptLayoutSettings;
    /** The optional store photo (versioned URL), or null for the fallback. */
    image_url: string | null;
    facebook_url: string | null;
    website_url: string | null;
};

const statusLabels: Record<BranchStatus, string> = {
    active: 'Active',
    temporarily_closed: 'Temporarily closed',
    inactive: 'Inactive',
};
/** The Owner standalone Settings tabs that have a real backend: no business-profile fields are invented. */
const SETTINGS_TABS = [
    ['branches', 'Branch Management'],
    ['receipt', 'Receipt'],
    ['qr', 'Customer QR'],
    ['screen', 'Customer Screen'],
] as const;

type SettingsTab = (typeof SETTINGS_TABS)[number][0];

/**
 * Business-wide Settings manages every Branch. A Branch-scoped Settings role sees only its selected assigned Branch
 * and edits its contact details, receipt and Customer QR settings; creating Branches and changing a Branch's code,
 * name or status stay business-wide (the server enforces both).
 */
type SettingsScope = {
    mode: 'branch' | 'business';
    can_create: boolean;
    can_edit_identity: boolean;
    branch: { id: string; name: string; code: string } | null;
};

export default function Branches({
    branches,
    scope,
}: {
    branches: Branch[];
    scope: SettingsScope;
}) {
    const branchMode = scope.mode === 'branch';
    const tabs = branchMode
        ? SETTINGS_TABS.map(
              ([value, label]) =>
                  [
                      value,
                      value === 'branches' ? 'Branch details' : label,
                  ] as const,
          )
        : SETTINGS_TABS;
    const [section, setSection] = useState<SettingsTab>('branches');
    const [receiptBranchId, setReceiptBranchId] = useState(
        branches[0]?.id ?? '',
    );
    const receiptBranch =
        branches.find((branch) => branch.id === receiptBranchId) ?? branches[0];
    const [screenBranchId, setScreenBranchId] = useState(branches[0]?.id ?? '');
    const screenBranch =
        branches.find((branch) => branch.id === screenBranchId) ?? branches[0];
    const [qrTabBranchId, setQrTabBranchId] = useState(branches[0]?.id ?? '');
    const qrTabBranch =
        branches.find((branch) => branch.id === qrTabBranchId) ?? branches[0];
    const [qrBranch, setQrBranch] = useState<Branch | null>(null);
    const [editing, setEditing] = useState<Branch | null | undefined>(
        undefined,
    );

    return (
        <>
            <Head title={branchMode ? 'Branch Settings' : 'Settings'} />
            <OwnerPage
                title={
                    branchMode
                        ? `Branch Settings — ${scope.branch?.code ?? ''}`
                        : 'Settings'
                }
                description={
                    branchMode
                        ? `Contact details, receipt, customer QR and customer screen settings of ${scope.branch?.name ?? 'this Branch'}.`
                        : 'Maintain branch details, availability, customer QR entry points, and current store state.'
                }
                action={
                    scope.can_create ? (
                        <Button
                            className={`${primaryActionClass} w-full md:w-auto`}
                            onClick={() => setEditing(null)}
                        >
                            <Plus className="size-4" /> Add branch
                        </Button>
                    ) : undefined
                }
                maxWidth="max-w-[1180px]"
            >
                <div className="self-start">
                    <SegmentedTabs
                        label="Settings section"
                        value={section}
                        options={tabs}
                        onChange={setSection}
                        size="lg"
                    />
                </div>
                {section === 'screen' ? (
                    screenBranch ? (
                        <section
                            aria-label="Customer Screen"
                            className={`${ownerPanelClass} flex max-w-[980px] flex-col gap-4 p-4 sm:p-[18px]`}
                        >
                            <div className="flex flex-wrap items-end justify-between gap-3">
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <h2 className="text-[15px] font-bold tracking-[-0.01em]">
                                        Customer screen
                                    </h2>
                                    <p className="text-xs text-[#767676]">
                                        Order confirmation, customer links and
                                        the ads the Branch's customer screens
                                        play by default.
                                    </p>
                                </div>
                                <label className="flex w-full max-w-xs flex-col gap-1.5 text-xs font-semibold">
                                    Branch
                                    <select
                                        className={controlClass}
                                        value={screenBranch.id}
                                        onChange={(event) =>
                                            setScreenBranchId(
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {branches.map((branch) => (
                                            <option
                                                key={branch.id}
                                                value={branch.id}
                                            >
                                                {branch.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <CustomerScreenSettingsPanel
                                key={`settings-${screenBranch.id}`}
                                branchId={screenBranch.id}
                            />
                            <div className="flex flex-col gap-0.5 border-t border-neutral-200 pt-4">
                                <h3 className="text-[14px] font-bold">Ads</h3>
                                <p className="text-xs text-[#767676]">
                                    Images and videos played in this order while
                                    neither MENU nor CUSTOMER DISPLAY is on.
                                </p>
                            </div>
                            <CustomerScreenMediaPanel
                                key={screenBranch.id}
                                branchId={screenBranch.id}
                            />
                        </section>
                    ) : (
                        <p className="text-sm text-neutral-500">
                            Add a branch to set up its customer screen.
                        </p>
                    )
                ) : section === 'qr' ? (
                    qrTabBranch ? (
                        <section
                            aria-label="Customer QR"
                            className={`${ownerPanelClass} flex max-w-[820px] flex-col gap-4 p-4 sm:p-[18px]`}
                        >
                            <div className="flex flex-wrap items-end justify-between gap-3">
                                <div className="flex min-w-0 flex-col gap-0.5">
                                    <h2 className="text-[15px] font-bold tracking-[-0.01em]">
                                        Customer QR
                                    </h2>
                                    <p className="text-xs text-[#767676]">
                                        The customer menu link, ordering switch
                                        and scan history of one Branch.
                                    </p>
                                </div>
                                <label className="flex w-full max-w-xs flex-col gap-1.5 text-xs font-semibold">
                                    Branch
                                    <select
                                        className={controlClass}
                                        value={qrTabBranch.id}
                                        onChange={(event) =>
                                            setQrTabBranchId(event.target.value)
                                        }
                                    >
                                        {branches.map((branch) => (
                                            <option
                                                key={branch.id}
                                                value={branch.id}
                                            >
                                                {branch.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                            </div>
                            <BranchQrPanel
                                key={qrTabBranch.id}
                                branch={qrTabBranch}
                            />
                        </section>
                    ) : (
                        <p className="text-sm text-neutral-500">
                            Add a branch to configure its customer QR.
                        </p>
                    )
                ) : section === 'receipt' ? (
                    <div className="space-y-4">
                        {receiptBranch ? (
                            <>
                                <label className="flex max-w-sm flex-col gap-2 text-xs font-semibold">
                                    Branch
                                    <select
                                        className={controlClass}
                                        value={receiptBranch.id}
                                        onChange={(event) =>
                                            setReceiptBranchId(
                                                event.target.value,
                                            )
                                        }
                                    >
                                        {branches.map((branch) => (
                                            <option
                                                key={branch.id}
                                                value={branch.id}
                                            >
                                                {branch.name}
                                            </option>
                                        ))}
                                    </select>
                                </label>
                                <ReceiptSettings
                                    key={receiptBranch.id}
                                    branch={receiptBranch}
                                />
                            </>
                        ) : (
                            <p className="text-sm text-neutral-500">
                                Add a branch to configure its receipt.
                            </p>
                        )}
                    </div>
                ) : branches.length === 0 ? (
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
                                <BranchPhoto branch={branch} />
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
                <DialogContent className="owner-surface max-h-[90dvh] overflow-y-auto rounded-[18px] bg-white text-neutral-950 sm:max-w-[740px]">
                    <DialogTitle>Customer QR</DialogTitle>
                    <DialogDescription>
                        Pongskilog · {qrBranch?.name}
                    </DialogDescription>
                    {qrBranch && (
                        <BranchQrPanel
                            branch={
                                branches.find(
                                    (item) => item.id === qrBranch.id,
                                ) ?? qrBranch
                            }
                        />
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
                            identityLocked={
                                editing !== null && !scope.can_edit_identity
                            }
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
    identityLocked = false,
    onSaved,
}: {
    branch: Branch | null;
    /** Code, name and status are business-wide administration: read-only for a Branch-scoped Settings role. */
    identityLocked?: boolean;
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
                        disabled={
                            form.processing ||
                            (identityLocked &&
                                (field === 'code' || field === 'name'))
                        }
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
                            (identityLocked &&
                            (field === 'code' || field === 'name')
                                ? 'Managed by a business-wide Settings role.'
                                : field === 'code'
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
                    disabled={form.processing || identityLocked}
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
            {branch && <BranchImageField branch={branch} />}
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

type QrHistory = {
    count: number;
    orders_placed: number;
    waiting_retrieval: number;
    visits: { data: { visited_at: string }[]; last_page: number };
};

function BranchQrPanel({ branch }: { branch: Branch }) {
    const [tab, setTab] = useState<'qr' | 'history'>('qr');
    const [date, setDate] = useState(() =>
        new Intl.DateTimeFormat('en-CA', { timeZone: 'Asia/Manila' }).format(
            new Date(),
        ),
    );
    const [page, setPage] = useState(1);
    const [history, setHistory] = useState<QrHistory | null>(null);
    const [error, setError] = useState('');
    const [busy, setBusy] = useState(false);
    const [enlarged, setEnlarged] = useState(false);
    const available =
        branch.status === 'active' &&
        branch.store_is_open &&
        branch.qr_ordering_enabled;
    useEffect(() => {
        if (tab !== 'history') return;
        let current = true;
        setHistory(null);
        setError('');
        void qrRequest<QrHistory>(
            qrHistory(branch.id, { query: { date, page } }),
        )
            .then((data) => {
                if (current) setHistory(data);
            })
            .catch((reason) => {
                if (current) setError(qrError(reason).message);
            });
        return () => {
            current = false;
        };
    }, [tab, date, page, branch.id]);
    return (
        <div className="space-y-4">
            <div className="inline-flex gap-1 rounded-xl bg-neutral-100 p-1">
                {(['qr', 'history'] as const).map((value) => (
                    <button
                        key={value}
                        aria-pressed={tab === value}
                        className={`${tab === value ? primaryActionClass : actionClass} px-4 py-2`}
                        onClick={() => setTab(value)}
                    >
                        {value === 'qr' ? 'QR' : 'History'}
                    </button>
                ))}
            </div>
            {error && (
                <p role="alert" className="text-sm text-red-700">
                    {error}
                </p>
            )}
            {tab === 'qr' ? (
                <div className="space-y-4 rounded-[18px] border border-neutral-200 bg-white p-4">
                    <div>
                        <h3 className="text-sm font-bold">
                            Customer ordering QR
                        </h3>
                        <p className="mt-1 max-w-prose text-xs leading-5 text-neutral-500">
                            One QR for the whole branch. Customers scan it,
                            order, and the cashier retrieves the order by
                            number.
                        </p>
                    </div>
                    <div className="flex flex-wrap items-center gap-4 rounded-[14px] border border-neutral-200 bg-[#f7f7f7] p-4">
                        <img
                            src={branch.qr_image}
                            alt={`Scan to order at ${branch.name}`}
                            className="size-36 rounded-xl border border-neutral-200 bg-white p-2"
                        />
                        <div className="min-w-0 flex-1 basis-60 space-y-3">
                            <label className="block text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                Public ordering link
                                <input
                                    readOnly
                                    value={branch.qr_url}
                                    onFocus={(event) => event.target.select()}
                                    className={`${controlClass} mt-2 font-mono text-xs`}
                                />
                            </label>
                            <div className="flex flex-wrap gap-2">
                                <button
                                    className={`${primaryActionClass} inline-flex items-center gap-2`}
                                    onClick={() => setEnlarged(true)}
                                >
                                    <QrCode size={15} /> View QR
                                </button>
                                <button
                                    className={`${actionClass} inline-flex items-center gap-2`}
                                    onClick={async () => {
                                        try {
                                            await navigator.clipboard.writeText(
                                                branch.qr_url,
                                            );
                                            toast.success(
                                                'Ordering link copied',
                                            );
                                        } catch {
                                            setError(
                                                'Copy blocked. Select and copy the public link above.',
                                            );
                                        }
                                    }}
                                >
                                    <Copy size={15} /> Copy link
                                </button>
                            </div>
                        </div>
                    </div>
                    <div className="space-y-2">
                        <div className="flex items-center justify-between gap-2">
                            <span className="text-[10px] font-semibold tracking-wider text-neutral-500 uppercase">
                                Ordering availability
                            </span>
                            <OwnerStatusBadge
                                tone={available ? 'green' : 'neutral'}
                            >
                                {available ? 'Open now' : 'Unavailable'}
                            </OwnerStatusBadge>
                        </div>
                        <SettingSwitch
                            checked={branch.qr_ordering_enabled}
                            disabled={busy}
                            label={
                                branch.qr_ordering_enabled
                                    ? 'QR ordering enabled'
                                    : 'QR ordering disabled'
                            }
                            description="Customers can order while this branch is active and its store session is open."
                            onChange={async (enabled) => {
                                setBusy(true);
                                setError('');
                                try {
                                    await qrRequest(saveQrSettings(branch.id), {
                                        qr_ordering_enabled: enabled,
                                    });
                                    router.reload({
                                        only: ['branches'],
                                        onFinish: () => setBusy(false),
                                    });
                                } catch (reason) {
                                    setError(qrError(reason).message);
                                    setBusy(false);
                                }
                            }}
                        />
                    </div>
                    <Dialog open={enlarged} onOpenChange={setEnlarged}>
                        <DialogContent className="owner-surface bg-white text-neutral-950 sm:max-w-md">
                            <DialogTitle>
                                {branch.name} · Customer QR
                            </DialogTitle>
                            <DialogDescription>
                                Scan to open the branch ordering menu.
                            </DialogDescription>
                            <img
                                src={branch.qr_image}
                                alt={`Ordering QR for ${branch.name}`}
                                className="mx-auto w-full max-w-80"
                            />
                            <a
                                href={branch.qr_url}
                                target="_blank"
                                rel="noreferrer"
                                className="text-center text-xs break-all underline"
                            >
                                {branch.qr_url}
                            </a>
                        </DialogContent>
                    </Dialog>
                </div>
            ) : (
                <div className="space-y-4">
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <h3 className="text-[11px] font-semibold tracking-wider text-neutral-500 uppercase">
                            QR activity
                        </h3>
                        <input
                            aria-label="Activity date"
                            type="date"
                            value={date}
                            className={`${controlClass} w-auto!`}
                            onChange={(event) => {
                                setDate(event.target.value);
                                setPage(1);
                            }}
                        />
                    </div>
                    <div className="grid grid-cols-3 gap-2">
                        {(
                            [
                                ['Scans / opens', history?.count],
                                ['Orders placed', history?.orders_placed],
                                [
                                    'Waiting retrieval',
                                    history?.waiting_retrieval,
                                ],
                            ] as const
                        ).map(([label, count]) => (
                            <div
                                key={label}
                                className="rounded-xl border border-neutral-200 bg-neutral-50 p-3"
                            >
                                <p className="text-[9px] font-semibold tracking-wider text-neutral-500 uppercase">
                                    {label}
                                </p>
                                <p className="mt-1 text-xl font-bold">
                                    {count ?? '—'}
                                </p>
                            </div>
                        ))}
                    </div>
                    <div className="flex justify-between gap-2 text-[11px] text-neutral-500">
                        <span className="font-semibold tracking-wider uppercase">
                            Scan history
                        </span>
                        <span>
                            {history
                                ? `${history.visits.data.length} of ${history.count} visits`
                                : 'Loading…'}
                        </span>
                    </div>
                    <div className="overflow-hidden rounded-xl border border-neutral-200 bg-white">
                        {!history && !error && (
                            <p
                                role="status"
                                className="animate-pulse p-5 text-sm text-neutral-500"
                            >
                                Loading activity…
                            </p>
                        )}
                        {history?.visits.data.map((visit, index) => (
                            <div
                                key={index}
                                className="flex items-center gap-3 border-b border-neutral-100 px-3 py-4 last:border-0"
                            >
                                <time className="w-20 shrink-0 text-xs font-semibold tabular-nums">
                                    {new Date(
                                        visit.visited_at.replace(' ', 'T') +
                                            (/[Z+]/.test(visit.visited_at)
                                                ? ''
                                                : 'Z'),
                                    ).toLocaleTimeString([], {
                                        timeZone: 'Asia/Manila',
                                        hour: '2-digit',
                                        minute: '2-digit',
                                    })}
                                </time>
                                <div className="min-w-0 flex-1">
                                    <p className="text-xs font-semibold">
                                        Customer ordering page
                                    </p>
                                    <p className="mt-1 text-[11px] text-neutral-500">
                                        {branch.name} · Branch QR link
                                    </p>
                                </div>
                                <span className="rounded-full bg-neutral-100 px-2 py-1 text-[9px] font-bold">
                                    OPENED
                                </span>
                            </div>
                        ))}
                        {history?.count === 0 && (
                            <p className="p-5 text-sm text-neutral-500">
                                No QR visits recorded for this date.
                            </p>
                        )}
                    </div>
                    <p className="text-[11px] leading-5 text-neutral-500">
                        Rapid repeat opens count once. Device details and
                        individual scan-to-order outcomes are not recorded.
                    </p>
                    {history && history.visits.last_page > 1 && (
                        <div className="flex items-center justify-between text-xs">
                            <button
                                className={actionClass}
                                disabled={page === 1}
                                onClick={() => setPage(page - 1)}
                            >
                                Previous
                            </button>
                            <span>
                                {page} / {history.visits.last_page}
                            </span>
                            <button
                                className={actionClass}
                                disabled={page >= history.visits.last_page}
                                onClick={() => setPage(page + 1)}
                            >
                                Next
                            </button>
                        </div>
                    )}
                </div>
            )}
        </div>
    );
}

/** The Branch's store photo, or a neutral fallback with its code when none was uploaded. */
function BranchPhoto({ branch }: { branch: Branch }) {
    return branch.image_url ? (
        <img
            src={branch.image_url}
            alt={`${branch.name} store`}
            loading="lazy"
            className="h-28 w-full rounded-xl border border-neutral-200 object-cover"
        />
    ) : (
        <div
            aria-hidden
            className="flex h-28 w-full flex-col items-center justify-center gap-1 rounded-xl border border-dashed border-neutral-300 bg-neutral-50 text-neutral-500"
        >
            <Building2 className="size-6" />
            <span className="text-[11px] font-semibold">{branch.code}</span>
        </div>
    );
}

/**
 * Optional store photo: JPG, PNG or WebP up to 5 MB, checked and re-encoded by the server (the same pipeline as
 * Product images). Saved immediately, separately from the Branch details form.
 */
function BranchImageField({ branch }: { branch: Branch }) {
    const input = useRef<HTMLInputElement>(null);
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const run = async (request: () => Promise<unknown>, message: string) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await request();
            toast.success(message);
            router.reload({ only: ['branches'], onFinish: () => setBusy(false) });
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };

    return (
        <div className="space-y-2">
            <Label>Store photo (optional)</Label>
            <BranchPhoto branch={branch} />
            <input
                ref={input}
                type="file"
                accept="image/jpeg,image/png,image/webp"
                className="hidden"
                aria-label="Choose a store photo"
                onChange={(event) => {
                    const file = event.target.files?.[0];
                    event.target.value = '';
                    if (!file) return;
                    if (file.size > 5 * 1024 * 1024) {
                        setError('Choose an image of at most 5 MB.');
                        return;
                    }
                    const payload = new FormData();
                    payload.set('image', file);
                    void run(
                        () => qrRequest(uploadBranchImage(branch.id), payload),
                        'Store photo saved',
                    );
                }}
            />
            <div className="flex flex-wrap gap-2">
                <Button
                    type="button"
                    variant="outline"
                    className={`${actionClass} min-h-11`}
                    disabled={busy}
                    onClick={() => input.current?.click()}
                >
                    {busy ? <Spinner /> : <ImagePlus className="size-3.5" />}
                    {branch.image_url ? 'Replace photo' : 'Upload photo'}
                </Button>
                {branch.image_url && (
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11`}
                        disabled={busy}
                        onClick={() =>
                            void run(
                                () => qrRequest(removeBranchImage(branch.id)),
                                'Store photo removed',
                            )
                        }
                    >
                        <Trash2 className="size-3.5" /> Remove
                    </Button>
                )}
            </div>
            <p
                role={error ? 'alert' : undefined}
                className={`text-xs ${error ? 'text-red-700' : 'text-neutral-600'}`}
            >
                {error ||
                    'JPG, PNG or WebP up to 5 MB. Saved right away and resized for the web.'}
            </p>
        </div>
    );
}
