import { router, useHttp } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    CatalogDialog,
    controlClass,
    money,
    primaryActionClass,
} from '@/components/catalog-ui';
import { OwnerStatusBadge } from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import {
    copySummary,
    matchesSearch,
    newAtDestination,
    operationsCopySummary,
    type AssortmentCandidate,
    type CopyPreview,
} from '@/lib/branch-assortment';
import { NEVER_COPIED } from '@/lib/operations-setup-copy';
import {
    copy as copyAssortment,
    destroy as removeFromAssortment,
    store as addToAssortment,
} from '@/routes/products/branch-assortment';
import { preview as copyPreview } from '@/routes/products/branch-assortment/copy';
import type { BranchSummary } from '@/types';

function PickRow({
    checked,
    onToggle,
    title,
    detail,
    badge,
}: {
    checked: boolean;
    onToggle: () => void;
    title: string;
    detail: string;
    badge?: React.ReactNode;
}) {
    return (
        <li>
            <label className="flex min-h-12 cursor-pointer items-center gap-3 rounded-lg px-2 py-1.5 hover:bg-[#f5f5f5]">
                <input
                    type="checkbox"
                    className="size-4 accent-[#111]"
                    checked={checked}
                    onChange={onToggle}
                />
                <span className="min-w-0 flex-1">
                    <span className="block truncate text-[13px] font-semibold">
                        {title}
                    </span>
                    <span className="block truncate text-[11.5px] text-[#767676]">
                        {detail}
                    </span>
                </span>
                {badge}
            </label>
        </li>
    );
}

function toggled(selected: Set<string>, id: string): Set<string> {
    const next = new Set(selected);
    if (next.has(id)) {
        next.delete(id);
    } else {
        next.add(id);
    }

    return next;
}

/**
 * "Add products to this Branch": global Products that are not in this Branch's assortment (including new Products sold
 * nowhere yet). Adding creates this Branch's membership only; the Product, its Category and Modifier Groups are the
 * same shared records at every Branch.
 */
export function AddProductsDialog({
    open,
    onClose,
    branch,
    candidates,
}: {
    open: boolean;
    onClose: () => void;
    branch: BranchSummary;
    candidates: AssortmentCandidate[] | undefined;
}) {
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [search, setSearch] = useState('');
    const [processing, setProcessing] = useState(false);

    useEffect(() => {
        if (open) {
            router.reload({ only: ['assortmentCandidates'] });
        }
    }, [open]);

    const visible = (candidates ?? []).filter((row) =>
        matchesSearch(row, search),
    );
    const close = () => {
        setSelected(new Set());
        setSearch('');
        onClose();
    };

    return (
        <CatalogDialog
            open={open}
            onClose={close}
            title={`Add products to ${branch.code}`}
            description={`Choose existing products that are not in the ${branch.code} assortment yet. Categories and options come with each product; nothing is duplicated. Each starts available at its default price.`}
        >
            {candidates === undefined ? (
                <p className="text-[12.5px] text-[#767676]" role="status">
                    Loading products…
                </p>
            ) : candidates.length === 0 ? (
                <p className="rounded-xl bg-[#f5f5f5] p-4 text-[12.5px] leading-5 text-[#555]">
                    Every product in the shared catalog is already in the{' '}
                    {branch.code} assortment. Products removed from{' '}
                    {branch.code} and new products appear here.
                </p>
            ) : (
                <>
                    <input
                        type="search"
                        aria-label="Search products to add"
                        placeholder="Search products"
                        className={controlClass}
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                    />
                    <ul className="max-h-[46dvh] space-y-0.5 overflow-y-auto">
                        {visible.map((row) => (
                            <PickRow
                                key={row.id}
                                checked={selected.has(row.id)}
                                onToggle={() =>
                                    setSelected(toggled(selected, row.id))
                                }
                                title={row.name}
                                detail={`${row.category_name} · ${money(row.default_price)}`}
                                badge={
                                    !row.is_active && (
                                        <OwnerStatusBadge tone="red">
                                            Disabled
                                        </OwnerStatusBadge>
                                    )
                                }
                            />
                        ))}
                    </ul>
                </>
            )}
            <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={close}
                >
                    Cancel
                </Button>
                <Button
                    className={primaryActionClass}
                    disabled={selected.size === 0 || processing}
                    onClick={() =>
                        router.post(
                            addToAssortment.url(),
                            { product_ids: [...selected] },
                            {
                                preserveScroll: true,
                                onStart: () => setProcessing(true),
                                onFinish: () => setProcessing(false),
                                onSuccess: close,
                                onError: (errors) =>
                                    toast.error(
                                        Object.values(errors)[0] ??
                                            'The products could not be added.',
                                    ),
                            },
                        )
                    }
                >
                    {processing
                        ? 'Adding…'
                        : `Add ${selected.size || ''} to ${branch.code}`}
                </Button>
            </div>
        </CatalogDialog>
    );
}

/**
 * "Copy products from another Branch": review the Products the source Branch sells with its configuration (available,
 * price, tracking, low-stock threshold) and add them to this Branch. Optionally also copy their Operations setup
 * (recipe mode, recipes, add-on effects, the Ingredients they need and their Plans) as a one-time clone. Only Branches
 * this account may access are offered and the server re-checks both. Stock, movements, purchases, sales and Store
 * Sessions are never copied; existing settings here are kept unless replacing is explicitly chosen and confirmed.
 */
export function CopyFromBranchDialog({
    open,
    onClose,
    branch,
    sources,
    canCopyOperations,
}: {
    open: boolean;
    onClose: () => void;
    branch: BranchSummary;
    sources: BranchSummary[];
    canCopyOperations: boolean;
}) {
    const request = useHttp<Record<string, never>, CopyPreview>({});
    const [sourceId, setSourceId] = useState('');
    const [preview, setPreview] = useState<CopyPreview | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [overwrite, setOverwrite] = useState(false);
    const [withOperations, setWithOperations] = useState(false);
    const [search, setSearch] = useState('');
    const [reviewing, setReviewing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const rows = preview?.products ?? [];
    const summary = copySummary(rows, selected, overwrite);
    const operations =
        withOperations && preview?.operations
            ? operationsCopySummary(preview.operations, selected, overwrite)
            : null;
    const visible = rows.filter((row) => matchesSearch(row, search));

    const close = () => {
        setSourceId('');
        setPreview(null);
        setLoadError(null);
        setSelected(new Set());
        setOverwrite(false);
        setWithOperations(false);
        setSearch('');
        setReviewing(false);
        onClose();
    };
    const load = (id: string) => {
        setSourceId(id);
        setPreview(null);
        setLoadError(null);
        setSelected(new Set());
        setReviewing(false);
        if (id === '') {
            return;
        }
        request
            .get(copyPreview.url({ query: { source_branch_id: id } }), {
                headers: { Accept: 'application/json' },
            })
            .then((result) => {
                setPreview(result);
                setSelected(new Set(newAtDestination(result.products)));
            })
            .catch(() =>
                setLoadError(
                    'That Branch could not be loaded. Choose another Branch.',
                ),
            );
    };

    return (
        <CatalogDialog
            open={open}
            onClose={close}
            wide
            title={`Copy products into ${branch.code}`}
            description="Adds the selected products with their Branch settings: availability, price, stock tracking and low-stock threshold. Stock quantities, history and sales are never copied."
        >
            <label className="block space-y-2">
                <span className="text-[11px] font-semibold tracking-[0.06em] text-[#777] uppercase">
                    Copy from
                </span>
                <select
                    className={controlClass}
                    value={sourceId}
                    onChange={(event) => load(event.target.value)}
                >
                    <option value="">Choose a Branch</option>
                    {sources.map((source) => (
                        <option key={source.id} value={source.id}>
                            {source.name} · {source.code}
                        </option>
                    ))}
                </select>
            </label>
            {request.processing && (
                <p role="status" className="text-[12.5px] text-[#767676]">
                    Loading products…
                </p>
            )}
            {loadError && (
                <p role="alert" className="text-[12.5px] text-red-700">
                    {loadError}
                </p>
            )}
            {preview && !reviewing && (
                <>
                    <div className="flex flex-wrap items-center gap-2">
                        <input
                            type="search"
                            aria-label="Search products to copy"
                            placeholder="Search products"
                            className={`${controlClass} flex-1 basis-48`}
                            value={search}
                            onChange={(event) => setSearch(event.target.value)}
                        />
                        <Button
                            type="button"
                            variant="outline"
                            className={actionClass}
                            onClick={() =>
                                setSelected(new Set(newAtDestination(rows)))
                            }
                        >
                            Select new only
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            className={actionClass}
                            onClick={() => setSelected(new Set())}
                        >
                            Clear
                        </Button>
                    </div>
                    <ul className="max-h-[40dvh] space-y-0.5 overflow-y-auto">
                        {visible.map((row) => (
                            <PickRow
                                key={row.product_id}
                                checked={selected.has(row.product_id)}
                                onToggle={() =>
                                    setSelected(
                                        toggled(selected, row.product_id),
                                    )
                                }
                                title={row.name}
                                detail={`${preview.source.code}: ${row.source.sold ? 'Available' : 'Unavailable'} · ${money(row.source.effective_price)}${row.source.tracks_inventory ? ' · Tracks stock' : ''}`}
                                badge={
                                    row.destination.configured ? (
                                        <OwnerStatusBadge tone="amber">
                                            In {branch.code}
                                        </OwnerStatusBadge>
                                    ) : (
                                        <OwnerStatusBadge tone="outline">
                                            New
                                        </OwnerStatusBadge>
                                    )
                                }
                            />
                        ))}
                    </ul>
                    <label className="flex min-h-11 items-start gap-3 rounded-xl border border-[#e5e5e5] p-3 text-[12.5px] leading-5">
                        <input
                            type="checkbox"
                            className="mt-1 size-4 accent-[#111]"
                            checked={overwrite}
                            onChange={(event) =>
                                setOverwrite(event.target.checked)
                            }
                        />
                        <span>
                            <span className="block font-semibold">
                                Replace settings already set at {branch.code}
                            </span>
                            Off by default: products already in {branch.code}{' '}
                            keep their current price, settings and Operations
                            setup. Replacing affects future sales only;
                            historical sales remain unchanged.
                        </span>
                    </label>
                    {canCopyOperations && preview.operations && (
                        <label className="flex min-h-11 items-start gap-3 rounded-xl border border-[#e5e5e5] p-3 text-[12.5px] leading-5">
                            <input
                                type="checkbox"
                                className="mt-1 size-4 accent-[#111]"
                                checked={withOperations}
                                onChange={(event) =>
                                    setWithOperations(event.target.checked)
                                }
                            />
                            <span>
                                <span className="block font-semibold">
                                    Copy Operations setup for selected products
                                </span>
                                Recipe mode, recipes, add-on ingredient effects,
                                the ingredients they use and their Pamalengke
                                Plans, cloned once for {branch.code}. Ingredient
                                stock is never copied.
                            </span>
                        </label>
                    )}
                </>
            )}
            {preview && reviewing && (
                <div className="space-y-3 rounded-xl bg-[#f5f5f5] p-4 text-[13px] leading-6">
                    <dl className="grid grid-cols-[auto_minmax(0,1fr)] gap-x-3">
                        <dt className="text-[#767676]">Source</dt>
                        <dd className="font-semibold">
                            {preview.source.name} · {preview.source.code}
                        </dd>
                        <dt className="text-[#767676]">Destination</dt>
                        <dd className="font-semibold">
                            {branch.name} · {branch.code}
                        </dd>
                        <dt className="text-[#767676]">Products</dt>
                        <dd className="font-semibold">{selected.size}</dd>
                    </dl>
                    <ul className="list-disc pl-5">
                        <li>
                            {summary.copy} added to {branch.code}
                        </li>
                        {summary.overwrite > 0 && (
                            <li className="text-red-700">
                                {summary.overwrite} existing settings replaced
                            </li>
                        )}
                        {summary.skip > 0 && (
                            <li>
                                {summary.skip} already in {branch.code}, kept as
                                is
                            </li>
                        )}
                        {summary.tracked > 0 && (
                            <li>
                                {summary.tracked} will track stock at{' '}
                                {branch.code} starting from this Branch's own
                                stock (no quantities are copied)
                            </li>
                        )}
                    </ul>
                    {operations && (
                        <div>
                            <p className="font-semibold">Operations setup</p>
                            <ul className="list-disc pl-5">
                                <li>
                                    {operations.plans} Plans
                                    {operations.plansKept > 0 &&
                                        ` (${operations.plansKept} already here, kept)`}
                                </li>
                                <li>
                                    {operations.ingredients} Ingredients
                                    {operations.ingredientsKept > 0 &&
                                        ` (${operations.ingredientsKept} already here, kept)`}
                                </li>
                                <li>
                                    {operations.recipes} Recipes ·{' '}
                                    {operations.effects} Add-on effects
                                    {operations.direct > 0 &&
                                        ` · ${operations.direct} No recipe needed`}
                                </li>
                                {operations.configuredKept > 0 && (
                                    <li>
                                        {operations.configuredKept} products
                                        already configured at {branch.code},
                                        kept as is
                                    </li>
                                )}
                                {operations.conflicts > 0 && (
                                    <li className="text-amber-800">
                                        {operations.conflicts} ingredients use
                                        another unit at {branch.code}; recipes
                                        needing them are skipped
                                    </li>
                                )}
                            </ul>
                        </div>
                    )}
                    <div>
                        <p className="font-semibold">Will NOT copy</p>
                        <ul className="list-disc pl-5 text-[#555]">
                            {NEVER_COPIED.map((line) => (
                                <li key={line}>{line}</li>
                            ))}
                        </ul>
                    </div>
                    {overwrite && (
                        <p className="rounded-lg border border-red-200 bg-red-50 p-2.5 text-[12.5px] text-red-800">
                            Replacing configuration affects future sales only.
                            Historical sales remain unchanged.
                        </p>
                    )}
                </div>
            )}
            <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={reviewing ? () => setReviewing(false) : close}
                >
                    {reviewing ? 'Back' : 'Cancel'}
                </Button>
                {reviewing ? (
                    <Button
                        className={primaryActionClass}
                        disabled={processing}
                        onClick={() =>
                            router.post(
                                copyAssortment.url(),
                                {
                                    source_branch_id: sourceId,
                                    product_ids: [...selected],
                                    overwrite,
                                    copy_operations: withOperations,
                                },
                                {
                                    preserveScroll: true,
                                    onStart: () => setProcessing(true),
                                    onFinish: () => setProcessing(false),
                                    onSuccess: close,
                                    onError: (errors) =>
                                        toast.error(
                                            Object.values(errors)[0] ??
                                                'The products could not be copied.',
                                        ),
                                },
                            )
                        }
                    >
                        {processing ? 'Copying…' : 'Confirm copy'}
                    </Button>
                ) : (
                    <Button
                        className={primaryActionClass}
                        disabled={
                            preview === null ||
                            selected.size === 0 ||
                            (summary.copy + summary.overwrite === 0 &&
                                !withOperations)
                        }
                        onClick={() => setReviewing(true)}
                    >
                        Review {selected.size || ''} selected
                    </Button>
                )}
            </div>
        </CatalogDialog>
    );
}

/**
 * "Remove from this Branch": ends the Product's membership in the Branch assortment (POS and Customer QR stop selling it
 * here and it leaves this Branch's Plans). Different from unavailable. The global Product, other Branches, stock balance
 * and every past movement, Order and recipe snapshot are kept; it can be added back later.
 */
export function RemoveFromBranchDialog({
    product,
    branch,
    onClose,
}: {
    product: { id: string; name: string } | null;
    branch: BranchSummary;
    onClose: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    return (
        <CatalogDialog
            open={product !== null}
            onClose={() => !processing && onClose()}
            title={`Remove ${product?.name ?? 'product'} from ${branch.code}?`}
            description={`It stops selling at ${branch.name} (POS and QR) and leaves ${branch.code}'s Plans. To pause it instead, mark it unavailable.`}
        >
            <ul className="list-disc space-y-1 rounded-xl bg-[#f5f5f5] p-4 pl-8 text-[12.5px] leading-5 text-[#444]">
                <li>
                    The product stays in the shared catalog and at other
                    Branches.
                </li>
                <li>
                    {branch.code} stock balance and movement history are kept
                    (never zeroed).
                </li>
                <li>
                    Past orders, receipts and recipe snapshots stay unchanged.
                </li>
                <li>You can add it back to {branch.code} later.</li>
            </ul>
            <div className="grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                <Button
                    variant="outline"
                    className={actionClass}
                    disabled={processing}
                    onClick={onClose}
                >
                    Cancel
                </Button>
                <Button
                    className={`${primaryActionClass} bg-red-700 hover:bg-red-800`}
                    disabled={processing || product === null}
                    onClick={() =>
                        product &&
                        router.delete(removeFromAssortment.url(), {
                            data: { product_ids: [product.id] },
                            preserveScroll: true,
                            onStart: () => setProcessing(true),
                            onFinish: () => setProcessing(false),
                            onSuccess: onClose,
                            onError: (errors) =>
                                toast.error(
                                    Object.values(errors)[0] ??
                                        `Unable to remove ${product.name}.`,
                                ),
                        })
                    }
                >
                    {processing ? 'Removing…' : `Remove from ${branch.code}`}
                </Button>
            </div>
        </CatalogDialog>
    );
}
