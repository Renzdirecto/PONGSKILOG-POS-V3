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
    type AssortmentCandidate,
    type CopyPreview,
} from '@/lib/branch-assortment';
import {
    copy as copyAssortment,
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
 * "Add products to this Branch": canonical Products this Branch does not sell yet. Adding only changes the Branch's
 * configuration; the Product, its Category and Modifier Groups are the same shared records at every Branch.
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
            description={`Choose existing products that ${branch.name} does not sell yet. Prices, categories and options come with each product; nothing is duplicated.`}
        >
            {candidates === undefined ? (
                <p className="text-[12.5px] text-[#767676]" role="status">
                    Loading products…
                </p>
            ) : candidates.length === 0 ? (
                <p className="rounded-xl bg-[#f5f5f5] p-4 text-[12.5px] leading-5 text-[#555]">
                    {branch.name} already sells every product in the shared
                    catalog. Products removed from this Branch appear here.
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
 * "Copy products from another Branch": review the source Branch's product configuration (sold here, price, tracking,
 * low-stock threshold) and apply it to this Branch. Only Branches this account may access are offered and the server
 * re-checks both. Stock, movements, sales and Store Sessions are never copied; existing settings here are kept unless
 * overwriting is explicitly chosen and confirmed.
 */
export function CopyFromBranchDialog({
    open,
    onClose,
    branch,
    sources,
}: {
    open: boolean;
    onClose: () => void;
    branch: BranchSummary;
    sources: BranchSummary[];
}) {
    const request = useHttp<Record<string, never>, CopyPreview>({});
    const [sourceId, setSourceId] = useState('');
    const [preview, setPreview] = useState<CopyPreview | null>(null);
    const [loadError, setLoadError] = useState<string | null>(null);
    const [selected, setSelected] = useState<Set<string>>(new Set());
    const [overwrite, setOverwrite] = useState(false);
    const [search, setSearch] = useState('');
    const [reviewing, setReviewing] = useState(false);
    const [processing, setProcessing] = useState(false);
    const rows = preview?.products ?? [];
    const summary = copySummary(rows, selected, overwrite);
    const visible = rows.filter((row) => matchesSearch(row, search));

    const close = () => {
        setSourceId('');
        setPreview(null);
        setLoadError(null);
        setSelected(new Set());
        setOverwrite(false);
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
            description="Copies each selected product's Branch settings: sold here, price, stock tracking and low-stock threshold. Stock quantities, history and sales are never copied."
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
                                detail={`${preview.source.code}: ${row.source.sold ? 'Sold' : 'Not sold'} · ${money(row.source.effective_price)}${row.source.tracks_inventory ? ' · Tracks stock' : ''}`}
                                badge={
                                    row.destination.configured ? (
                                        <OwnerStatusBadge tone="amber">
                                            Set at {branch.code}
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
                            Off by default: products already configured here
                            keep their current price and settings.
                        </span>
                    </label>
                </>
            )}
            {preview && reviewing && (
                <div className="space-y-2 rounded-xl bg-[#f5f5f5] p-4 text-[13px] leading-6">
                    <p className="font-semibold">
                        Copy from {preview.source.name} into {branch.name}
                    </p>
                    <ul className="list-disc pl-5">
                        <li>
                            {summary.copy} new at {branch.code}
                        </li>
                        {summary.overwrite > 0 && (
                            <li className="text-red-700">
                                {summary.overwrite} existing settings replaced
                            </li>
                        )}
                        {summary.skip > 0 && (
                            <li>{summary.skip} already set here, kept as is</li>
                        )}
                        {summary.tracked > 0 && (
                            <li>
                                {summary.tracked} will track stock at{' '}
                                {branch.code} starting from this Branch's own
                                stock (no quantities are copied)
                            </li>
                        )}
                    </ul>
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
                            summary.copy + summary.overwrite === 0
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
