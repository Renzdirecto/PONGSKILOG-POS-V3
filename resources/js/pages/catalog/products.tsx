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
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { ProductEditorForm } from '@/components/product-editor-form';
import { Button } from '@/components/ui/button';
import { index, update } from '@/routes/products';
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
};

type ViewMode = 'tile' | 'list';

export default function Products({
    products,
    categories,
    modifierGroups,
    branchConfigurations,
    filters,
}: Props) {
    const page = usePage<{ branchContext: BranchContext }>();
    const { branchContext } = page.props;
    const createRequested = page.url.includes('create=product');
    const [editing, setEditing] = useState<CatalogProduct | null | undefined>(
        createRequested ? null : undefined,
    );
    const [viewMode, setViewMode] = useState<ViewMode>(() => {
        if (typeof window === 'undefined') return 'tile';
        return window.localStorage.getItem('owner-products-view') === 'list'
            ? 'list'
            : 'tile';
    });
    useEffect(() => {
        window.localStorage.setItem('owner-products-view', viewMode);
    }, [viewMode]);
    const openEditor = (product: CatalogProduct | null) => {
        setEditing(product);
    };

    return (
        <CatalogPage
            tab="Products"
            counts={{
                Products: products.total,
                Categories: categories.length,
                Groups: modifierGroups.length,
            }}
        >
            {categories.length === 0 && (
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
            {products.data.length === 0 ? (
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
                    {products.data.map((product) => (
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
                            hasInventoryScope={branchContext.current !== null}
                            list={viewMode === 'list'}
                            onEdit={() => openEditor(product)}
                        />
                    ))}
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
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? editing.name : 'Add product'}
                description={editing ? 'Edit product' : 'Add product'}
                wide
                standalone
            >
                {editing !== undefined && (
                    <ProductEditorForm
                        key={editing?.id ?? 'new'}
                        product={editing}
                        categories={categories}
                        groups={modifierGroups}
                        branches={branchConfigurations}
                        onSaved={() => setEditing(undefined)}
                        onCancel={() => setEditing(undefined)}
                    />
                )}
            </CatalogDialog>
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
                <OwnerStatusBadge
                    tone={product.is_active ? 'green' : 'red'}
                >
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
