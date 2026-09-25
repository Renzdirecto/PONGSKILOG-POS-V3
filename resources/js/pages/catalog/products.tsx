import { router, useForm, usePage } from '@inertiajs/react';
import {
    ImageIcon,
    LayoutGrid,
    List,
    Pencil,
    SlidersHorizontal,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    CatalogDialog,
    CatalogPage,
    controlClass,
    money,
} from '@/components/catalog-ui';
import {
    AddProductsDialog,
    CopyFromBranchDialog,
    RemoveFromBranchDialog,
} from '@/components/branch-assortment-dialogs';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { ProductEditorForm } from '@/components/product-editor-form';
import { Button } from '@/components/ui/button';
import {
    restoredOwnerViewMode,
    type OwnerViewMode,
} from '@/lib/owner-view-preference';
import type {
    AssortmentCandidate,
    ProductScope,
} from '@/lib/branch-assortment';
import { index, update } from '@/routes/products';
import { update as updateBranchProduct } from '@/routes/products/branches';
import type { BranchContext } from '@/types';
import type {
    BranchConfiguration,
    CatalogChoice,
    CatalogProduct,
    ModifierGroup,
} from '@/types/catalog';
import type { StockStatus } from '@/types/inventory';

type Filters = { search?: string; category?: string; status?: string };
type Props = {
    products: {
        data: CatalogProduct[];
        total: number;
        current_page: number;
        last_page: number;
    };
    categories: CatalogChoice[];
    modifierGroups: ModifierGroup[];
    branchConfigurations: BranchConfiguration[];
    filters: Filters;
    scope: ProductScope;
    assortmentCandidates?: AssortmentCandidate[];
};

export default function Products({
    products,
    categories,
    modifierGroups,
    branchConfigurations,
    filters,
    scope,
    assortmentCandidates,
}: Props) {
    const page = usePage<{ branchContext: BranchContext }>();
    const { branchContext } = page.props;
    const createRequested = page.url.includes('create=product');
    /** Operations › Recipes links here with ?edit=<product id> to open that Product's settings directly. */
    const requested = new URLSearchParams(page.url.split('?')[1] ?? '');
    const editRequested = requested.get('edit');
    const [editing, setEditing] = useState<CatalogProduct | null | undefined>(
        createRequested
            ? null
            : (products.data.find((product) => product.id === editRequested) ??
                  undefined),
    );
    const [editingSection, setEditingSection] = useState<'product' | 'branch'>(
        requested.get('section') === 'branch' ? 'branch' : 'product',
    );
    const [viewMode, setViewMode] = useState<OwnerViewMode>('tile');
    const [assortmentDialog, setAssortmentDialog] = useState<
        'add' | 'copy' | null
    >(null);
    const [removing, setRemoving] = useState<CatalogProduct | null>(null);
    /** Branch-scoped Product management edits only the selected Branch's configuration, never the shared definition. */
    const branchOnly = !scope.can_edit_definitions;
    /** A selected Branch shows its own assortment; All Branches shows the global catalog with membership per Branch. */
    const assortment = scope.branch;
    const filtered = Boolean(
        filters.search || filters.category || filters.status,
    );

    useEffect(() => {
        setViewMode(
            restoredOwnerViewMode(
                window.localStorage.getItem('owner-products-view'),
            ),
        );
    }, []);

    useEffect(() => {
        const storedViewMode = window.localStorage.getItem(
            'owner-products-view',
        );

        if (storedViewMode !== 'list' || viewMode === 'list') {
            window.localStorage.setItem('owner-products-view', viewMode);
        }
    }, [viewMode]);
    const openEditor = (
        product: CatalogProduct | null,
        section: 'product' | 'branch' = 'product',
    ) => {
        setEditingSection(section);
        setEditing(product);
    };

    return (
        <CatalogPage
            tab="Products"
            definitions={scope.can_edit_definitions}
            branchLabel={scope.branch?.code}
            counts={{
                Products: products.total,
                Categories: categories.length,
                Groups: modifierGroups.length,
            }}
        >
            {assortment && (
                <div className="flex flex-wrap items-center gap-2 rounded-[11px] border border-[#e5e5e5] bg-white p-2.5">
                    <p className="min-w-0 flex-1 basis-56 px-1 text-[12.5px] leading-5 text-[#555]">
                        <span className="block text-[14px] font-bold text-[#111]">
                            Products — {assortment.code}
                        </span>
                        Only products in the {assortment.name} assortment are
                        listed and sold here. Add shared products or copy them
                        from another Branch. Stock is never copied.
                    </p>
                    <Button
                        type="button"
                        variant="outline"
                        className={actionClass}
                        onClick={() => setAssortmentDialog('add')}
                    >
                        Add products to {assortment.code}
                    </Button>
                    {scope.copy_sources.length > 0 && (
                        <Button
                            type="button"
                            variant="outline"
                            className={actionClass}
                            onClick={() => setAssortmentDialog('copy')}
                        >
                            Copy from another Branch
                        </Button>
                    )}
                </div>
            )}
            {scope.can_edit_definitions && categories.length === 0 && (
                <div className="rounded-[11px] border border-amber-200 bg-amber-50 p-3 text-[12.5px] text-amber-900">
                    Create a category before adding a product.
                </div>
            )}
            <ProductFilters
                key={JSON.stringify(filters)}
                filters={filters}
                categories={categories}
                hasInventoryScope={branchContext.current !== null}
            />
            <div className="flex flex-wrap items-center justify-between gap-2 text-xs text-[#767676]">
                <p role="status">
                    {products.data.length} of {products.total} products
                </p>
                <div className="flex items-center gap-2">
                    <p className="hidden sm:block">
                        {branchContext.current
                            ? `Stock shown for ${branchContext.current.name}`
                            : 'Select a branch to view stock status'}
                    </p>
                    <div
                        className="flex rounded-[10px] bg-[#ededed] p-1"
                        aria-label="Product view"
                    >
                        {(
                            [
                                ['tile', LayoutGrid, 'Tile view'],
                                ['list', List, 'List view'],
                            ] as const
                        ).map(([mode, Icon, label]) => (
                            <button
                                key={mode}
                                type="button"
                                title={label}
                                aria-label={label}
                                aria-pressed={viewMode === mode}
                                onClick={() => setViewMode(mode)}
                                className={`flex size-10 items-center justify-center rounded-lg ${viewMode === mode ? 'bg-white text-[#111] shadow-sm' : 'text-[#777]'}`}
                            >
                                <Icon className="size-4" />
                            </button>
                        ))}
                    </div>
                </div>
            </div>
            {products.data.length === 0 && assortment && !filtered ? (
                <div className={`${ownerPanelClass} px-5 py-14 text-center`}>
                    <span className="mx-auto flex size-[52px] items-center justify-center rounded-[14px] bg-[#f2f2f2] text-[#767676]">
                        <SlidersHorizontal className="size-6" />
                    </span>
                    <h2 className="mt-3 text-[15px] font-semibold">
                        No products in {assortment.code} yet.
                    </h2>
                    <p className="mx-auto mt-2 max-w-[46ch] text-[12.5px] leading-5 text-[#767676]">
                        {assortment.name} sells nothing until products are
                        added. POS and Customer QR stay empty until then.
                    </p>
                    <div className="mt-4 flex flex-wrap justify-center gap-2">
                        <Button
                            type="button"
                            className={actionClass}
                            onClick={() => setAssortmentDialog('add')}
                        >
                            Add products
                        </Button>
                        {scope.copy_sources.length > 0 && (
                            <Button
                                type="button"
                                variant="outline"
                                className={actionClass}
                                onClick={() => setAssortmentDialog('copy')}
                            >
                                Copy from another Branch
                            </Button>
                        )}
                    </div>
                </div>
            ) : products.data.length === 0 ? (
                <div className={`${ownerPanelClass} px-5 py-14 text-center`}>
                    <span className="mx-auto flex size-[52px] items-center justify-center rounded-[14px] bg-[#f2f2f2] text-[#767676]">
                        <SlidersHorizontal className="size-6" />
                    </span>
                    <h2 className="mt-3 text-[15px] font-semibold">
                        No products match.
                    </h2>
                    <p className="mx-auto mt-2 max-w-[40ch] text-[12.5px] leading-5 text-[#767676]">
                        Change the search or filters to see the rest of the
                        menu.
                    </p>
                </div>
            ) : (
                <ul
                    className={`grid gap-2.5 ${viewMode === 'tile' ? 'sm:grid-cols-2 xl:grid-cols-3' : 'grid-cols-1'}`}
                >
                    {products.data.map((product) =>
                        assortment ? (
                            <BranchProductCard
                                key={product.id}
                                product={product}
                                list={viewMode === 'list'}
                                onEdit={() => openEditor(product, 'branch')}
                                onEditProduct={
                                    branchOnly
                                        ? undefined
                                        : () => openEditor(product, 'product')
                                }
                                onRemove={() => setRemoving(product)}
                            />
                        ) : (
                            <ProductCard
                                key={JSON.stringify([
                                    product.id,
                                    product.name,
                                    product.description,
                                    product.category_id,
                                    product.default_price,
                                    product.is_active,
                                    product.modifier_group_ids,
                                ])}
                                product={product}
                                hasInventoryScope={
                                    branchContext.current !== null
                                }
                                list={viewMode === 'list'}
                                onEdit={() => openEditor(product)}
                            />
                        ),
                    )}
                </ul>
            )}
            {products.last_page > 1 && (
                <nav
                    aria-label="Product pagination"
                    className="flex items-center justify-between gap-3"
                >
                    <Button
                        variant="outline"
                        className={actionClass}
                        disabled={products.current_page <= 1}
                        onClick={() =>
                            router.get(
                                index.url({
                                    query: {
                                        ...filters,
                                        page: products.current_page - 1,
                                    },
                                }),
                            )
                        }
                    >
                        Previous
                    </Button>
                    <span className="text-xs text-[#666]">
                        Page {products.current_page} of {products.last_page}
                    </span>
                    <Button
                        variant="outline"
                        className={actionClass}
                        disabled={products.current_page >= products.last_page}
                        onClick={() =>
                            router.get(
                                index.url({
                                    query: {
                                        ...filters,
                                        page: products.current_page + 1,
                                    },
                                }),
                            )
                        }
                    >
                        Next
                    </Button>
                </nav>
            )}
            <CatalogDialog
                open={
                    editing !== undefined && (!branchOnly || editing !== null)
                }
                onClose={() => setEditing(undefined)}
                title={editing ? editing.name : 'Add product'}
                description={
                    branchOnly
                        ? `${scope.branch?.code ?? 'Branch'} settings`
                        : editing
                          ? 'Edit product'
                          : 'Add product'
                }
                wide
                standalone
            >
                {editing !== undefined && (!branchOnly || editing !== null) && (
                    <ProductEditorForm
                        key={editing?.id ?? 'new'}
                        product={editing}
                        categories={categories}
                        groups={modifierGroups}
                        branches={branchConfigurations}
                        branchOnly={branchOnly}
                        initialSection={editingSection}
                        onSaved={() => setEditing(undefined)}
                        onCancel={() => setEditing(undefined)}
                    />
                )}
            </CatalogDialog>
            {scope.branch && (
                <>
                    <AddProductsDialog
                        open={assortmentDialog === 'add'}
                        onClose={() => setAssortmentDialog(null)}
                        branch={scope.branch}
                        candidates={assortmentCandidates}
                    />
                    <CopyFromBranchDialog
                        open={assortmentDialog === 'copy'}
                        onClose={() => setAssortmentDialog(null)}
                        branch={scope.branch}
                        sources={scope.copy_sources}
                        canCopyOperations={scope.can_copy_operations}
                    />
                    <RemoveFromBranchDialog
                        product={removing}
                        branch={scope.branch}
                        onClose={() => setRemoving(null)}
                    />
                </>
            )}
        </CatalogPage>
    );
}

function ProductCard({
    product,
    hasInventoryScope,
    list,
    onEdit,
}: {
    product: CatalogProduct;
    hasInventoryScope: boolean;
    list: boolean;
    onEdit: () => void;
}) {
    const form = useForm({
        name: product.name,
        description: product.description ?? '',
        category_id: product.category_id,
        default_price: product.default_price,
        is_active: !product.is_active,
        modifier_group_ids: product.modifier_group_ids,
    });
    const submitting = useRef(false);
    const inventory = product.inventory;
    const membership = product.branch_prices
        .filter((price) => price.in_assortment)
        .map((price) => price.code);

    return (
        <li
            className={`${ownerPanelClass} min-w-0 gap-3 p-3.5 ${list ? 'grid sm:grid-cols-[minmax(240px,1fr)_auto_190px] sm:items-center' : 'flex flex-col'} ${product.is_active ? '' : 'border-red-300 bg-red-50/70 ring-1 ring-red-100'}`}
        >
            <div className="flex items-start gap-3">
                <ProductThumbnail product={product} />
                <div className="min-w-0 flex-1">
                    <h2 className="text-sm leading-[1.3] font-semibold break-words">
                        {product.name}
                    </h2>
                    <p className="mt-1 text-[11.5px] text-[#767676]">
                        {product.category_name}
                        {!product.category_active && ' · Disabled category'}
                    </p>
                    <p className="mt-1 text-base font-bold tracking-[-0.01em] tabular-nums">
                        {money(product.default_price)}
                    </p>
                    <p className="mt-1 text-[11.5px] text-[#555]">
                        {membership.length > 0
                            ? `Sold at ${membership.join(', ')}`
                            : 'Not sold at any Branch yet'}
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
                {hasInventoryScope && inventory ? (
                    <InventoryChip status={inventory.status}>
                        {inventory.status === 'not_tracked'
                            ? 'Not tracked'
                            : inventory.status === 'out_of_stock'
                              ? 'Out of stock'
                              : `${inventory.on_hand?.toLocaleString()} ${inventory.status === 'low_stock' ? 'left' : 'in stock'}`}
                    </InventoryChip>
                ) : (
                    <OwnerStatusBadge tone="neutral">
                        Select branch for stock
                    </OwnerStatusBadge>
                )}
                <OwnerStatusBadge tone={product.is_active ? 'green' : 'red'}>
                    {product.is_active ? 'Active' : 'Disabled'}
                </OwnerStatusBadge>
                {product.modifier_group_count > 0 && (
                    <OwnerStatusBadge tone="outline">
                        {product.modifier_group_count}{' '}
                        {product.modifier_group_count === 1
                            ? 'option group'
                            : 'option groups'}
                    </OwnerStatusBadge>
                )}
            </div>
            <div className="mt-auto grid grid-cols-2 gap-1.5">
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={onEdit}
                >
                    <Pencil className="size-3.5" /> Edit
                </Button>
                <Button
                    variant="outline"
                    disabled={form.processing}
                    className={`${actionClass} ${product.is_active ? 'text-red-700 hover:text-red-800' : 'border-[#111111] bg-[#111111] text-white hover:bg-neutral-800 hover:text-white'}`}
                    onClick={() => {
                        if (submitting.current) return;
                        submitting.current = true;
                        form.submit(update(product.id), {
                            preserveScroll: true,
                            onSuccess: () =>
                                toast.success(
                                    `${product.name} ${product.is_active ? 'disabled' : 'enabled'}`,
                                ),
                            onError: () =>
                                toast.error(
                                    `Unable to ${product.is_active ? 'disable' : 'enable'} ${product.name}`,
                                ),
                            onFinish: () => {
                                submitting.current = false;
                            },
                        });
                    }}
                >
                    {form.processing
                        ? 'Saving…'
                        : product.is_active
                          ? 'Disable'
                          : 'Enable'}
                </Button>
            </div>
        </li>
    );
}

/**
 * A Product of the selected Branch assortment: its price, availability and stock at that Branch. Actions are Branch
 * settings, Available / Unavailable (still in the assortment, temporarily not sellable) and Remove from the Branch (ends
 * membership). A business-wide manager may also open the shared definition; a Branch-scoped one never can.
 */
function BranchProductCard({
    product,
    list,
    onEdit,
    onEditProduct,
    onRemove,
}: {
    product: CatalogProduct;
    list: boolean;
    onEdit: () => void;
    onEditProduct?: () => void;
    onRemove: () => void;
}) {
    const config = product.branch_prices[0];
    const [saving, setSaving] = useState(false);
    const inventory = product.inventory;
    if (!config) {
        return null;
    }
    const available = config.is_available;

    return (
        <li
            className={`${ownerPanelClass} min-w-0 gap-3 p-3.5 ${list ? 'grid sm:grid-cols-[minmax(240px,1fr)_auto_minmax(190px,280px)] sm:items-center' : 'flex flex-col'} ${available ? '' : 'bg-[#fafafa]'}`}
        >
            <div className="flex items-start gap-3">
                <ProductThumbnail product={product} />
                <div className="min-w-0 flex-1">
                    <h2 className="text-sm leading-[1.3] font-semibold break-words">
                        {product.name}
                    </h2>
                    <p className="mt-1 text-[11.5px] text-[#767676]">
                        {product.category_name}
                    </p>
                    <p className="mt-1 text-base font-bold tracking-[-0.01em] tabular-nums">
                        {money(config.effective_price)}
                    </p>
                </div>
            </div>
            <div className="flex flex-wrap items-center gap-1.5">
                <OwnerStatusBadge tone={available ? 'green' : 'amber'}>
                    {available
                        ? `Available at ${config.code}`
                        : `Unavailable at ${config.code}`}
                </OwnerStatusBadge>
                {!product.is_active && (
                    <OwnerStatusBadge tone="red">
                        Disabled for every Branch
                    </OwnerStatusBadge>
                )}
                {inventory && inventory.status !== 'not_tracked' && (
                    <InventoryChip status={inventory.status}>
                        {inventory.status === 'out_of_stock'
                            ? 'Out of stock'
                            : `${inventory.on_hand?.toLocaleString()} ${inventory.status === 'low_stock' ? 'left' : 'in stock'}`}
                    </InventoryChip>
                )}
            </div>
            <div className="mt-auto grid grid-cols-2 gap-1.5">
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={onEdit}
                >
                    <Pencil className="size-3.5" /> Branch settings
                </Button>
                <Button
                    variant="outline"
                    disabled={saving}
                    className={actionClass}
                    onClick={() =>
                        router.put(
                            updateBranchProduct.url({
                                product: product.id,
                                branch: config.branch_id,
                            }),
                            {
                                price_override: config.price_override,
                                is_available: !available,
                                tracks_inventory: config.tracks_inventory,
                                low_stock_threshold: config.low_stock_threshold,
                            },
                            {
                                preserveScroll: true,
                                onStart: () => setSaving(true),
                                onFinish: () => setSaving(false),
                                onSuccess: () =>
                                    toast.success(
                                        `${product.name} is ${available ? 'unavailable' : 'available'} at ${config.code}`,
                                    ),
                                onError: (errors) =>
                                    toast.error(
                                        Object.values(errors)[0] ??
                                            `Unable to update ${product.name}`,
                                    ),
                            },
                        )
                    }
                >
                    {saving
                        ? 'Saving…'
                        : available
                          ? 'Mark unavailable'
                          : 'Mark available'}
                </Button>
                {onEditProduct && (
                    <Button
                        variant="outline"
                        className={actionClass}
                        onClick={onEditProduct}
                    >
                        Edit product
                    </Button>
                )}
                <Button
                    variant="outline"
                    className={`${actionClass} text-red-700 hover:text-red-800 ${onEditProduct ? '' : 'col-span-2'}`}
                    onClick={onRemove}
                >
                    Remove from {config.code}
                </Button>
            </div>
        </li>
    );
}

function ProductThumbnail({ product }: { product: CatalogProduct }) {
    const [failed, setFailed] = useState(false);

    return product.image_url && !failed ? (
        <img
            src={product.image_url}
            alt={product.name}
            loading="lazy"
            width={144}
            height={96}
            onError={() => setFailed(true)}
            className="h-24 w-36 shrink-0 rounded-xl bg-[#f2f2f2] object-cover"
        />
    ) : (
        <span className="flex h-24 w-36 shrink-0 items-center justify-center rounded-xl bg-[#f2f2f2] text-[#b5b5b5]">
            <ImageIcon className="size-5" aria-hidden="true" />
            <span className="sr-only">No image available</span>
        </span>
    );
}

function InventoryChip({
    status,
    children,
}: {
    status: StockStatus;
    children: ReactNode;
}) {
    const tone =
        status === 'out_of_stock'
            ? 'red'
            : status === 'low_stock'
              ? 'amber'
              : status === 'in_stock'
                ? 'green'
                : 'neutral';

    return <OwnerStatusBadge tone={tone}>{children}</OwnerStatusBadge>;
}

function ProductFilters({
    filters,
    categories,
    hasInventoryScope,
}: {
    filters: Filters;
    categories: CatalogChoice[];
    hasInventoryScope: boolean;
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    const [loading, setLoading] = useState(false);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }

        const timeout = window.setTimeout(() => {
            router.get(
                index.url(),
                { search, category, status },
                {
                    replace: true,
                    preserveScroll: true,
                    preserveState: true,
                    only: ['products', 'filters'],
                    onStart: () => setLoading(true),
                    onFinish: () => setLoading(false),
                },
            );
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [category, search, status]);

    return (
        <div className="flex flex-wrap items-end gap-2" aria-busy={loading}>
            <label className="min-w-0 flex-1 basis-56">
                <span className="sr-only">Search products</span>
                <input
                    type="search"
                    className={controlClass}
                    value={search}
                    maxLength={255}
                    placeholder="Search products"
                    onChange={(event) => setSearch(event.target.value)}
                />
            </label>
            <label className="min-w-0 flex-1 basis-40 sm:flex-none">
                <span className="sr-only">Category</span>
                <select
                    aria-label="Filter by category"
                    className={controlClass}
                    value={category}
                    onChange={(event) => setCategory(event.target.value)}
                >
                    <option value="">All categories</option>
                    {categories.map((item) => (
                        <option key={item.id} value={item.id}>
                            {item.name}
                        </option>
                    ))}
                </select>
            </label>
            <label className="min-w-0 flex-1 basis-40 sm:flex-none">
                <span className="sr-only">Status</span>
                <select
                    aria-label="Filter by status"
                    className={controlClass}
                    value={status}
                    onChange={(event) => setStatus(event.target.value)}
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Disabled</option>
                    <option value="low_stock" disabled={!hasInventoryScope}>
                        Low stock
                    </option>
                    <option value="out_of_stock" disabled={!hasInventoryScope}>
                        Out of stock
                    </option>
                </select>
            </label>
            <span
                role="status"
                className={`px-2 text-[11px] text-[#767676] ${loading ? 'opacity-100' : 'opacity-0'}`}
            >
                Updating…
            </span>
            {(filters.search || filters.category || filters.status) && (
                <Button
                    type="button"
                    variant="outline"
                    className={`${actionClass} flex-1 sm:flex-none`}
                    onClick={() => router.get(index())}
                >
                    Clear
                </Button>
            )}
        </div>
    );
}
