import { router, useForm, usePage } from '@inertiajs/react';
import { ImageIcon, Pencil, Plus, SlidersHorizontal } from 'lucide-react';
import { useRef, useState } from 'react';
import type { ReactNode } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    CatalogDialog,
    CatalogPage,
    controlClass,
    money,
    primaryActionClass,
} from '@/components/catalog-ui';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import {
    BranchPriceForm,
    ProductForm,
    ProductImageForm,
} from '@/components/product-forms';
import { Button } from '@/components/ui/button';
import { index, update } from '@/routes/products';
import type { BranchContext } from '@/types';
import type { CatalogChoice, CatalogProduct } from '@/types/catalog';
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
    modifierGroups: CatalogChoice[];
    filters: Filters;
};

type EditorTab = 'details' | 'image' | 'branches';

export default function Products({
    products,
    categories,
    modifierGroups,
    filters,
}: Props) {
    const { branchContext } = usePage<{ branchContext: BranchContext }>().props;
    const [editing, setEditing] = useState<CatalogProduct | null | undefined>();
    const [editorTab, setEditorTab] = useState<EditorTab>('details');
    const openEditor = (product: CatalogProduct | null) => {
        setEditorTab('details');
        setEditing(product);
    };

    return (
        <CatalogPage
            tab="Products"
            counts={{
                Products: products.total,
                Categories: categories.length,
                Modifiers: modifierGroups.length,
            }}
            action={
                <Button
                    className={`${primaryActionClass} w-full md:w-auto`}
                    disabled={categories.length === 0}
                    onClick={() => openEditor(null)}
                >
                    <Plus className="size-4" /> Add product
                </Button>
            }
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
                <p>
                    {branchContext.current
                        ? `Stock shown for ${branchContext.current.name}`
                        : 'Select a branch to view stock status'}
                </p>
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
                <ul className="grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3">
                    {products.data.map((product) => (
                        <ProductCard
                            key={`${product.id}-${product.is_active}`}
                            product={product}
                            hasInventoryScope={branchContext.current !== null}
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
                description={
                    editing
                        ? 'Edit product details and retain its branch-specific configuration.'
                        : 'Add a product to the global catalog.'
                }
                wide={editing !== null}
            >
                {editing !== undefined && (
                    <div className="flex flex-col gap-4">
                        {editing && (
                            <div
                                role="tablist"
                                aria-label="Product editor"
                                className="owner-hide-scrollbar flex gap-0.5 overflow-x-auto rounded-[10px] bg-[#f2f2f2] p-[3px]"
                            >
                                {(
                                    [
                                        ['details', 'Details'],
                                        ['image', 'Image'],
                                        ['branches', 'Branches'],
                                    ] as const
                                ).map(([value, label]) => (
                                    <button
                                        key={value}
                                        type="button"
                                        role="tab"
                                        aria-selected={editorTab === value}
                                        onClick={() => setEditorTab(value)}
                                        className={`min-h-10 flex-1 rounded-lg px-3 text-[12.5px] font-semibold ${editorTab === value ? 'bg-[#111111] text-white' : 'text-[#666]'}`}
                                    >
                                        {label}
                                    </button>
                                ))}
                            </div>
                        )}
                        {(editing === null || editorTab === 'details') && (
                            <ProductForm
                                key={editing?.id ?? 'new'}
                                product={editing}
                                categories={categories}
                                groups={modifierGroups}
                                onSaved={() => setEditing(undefined)}
                            />
                        )}
                        {editing && editorTab === 'image' && (
                            <ProductImageForm
                                key={editing.id}
                                product={editing}
                                onSaved={() => setEditing(undefined)}
                            />
                        )}
                        {editing && editorTab === 'branches' && (
                            <div className="grid gap-3 md:grid-cols-2">
                                {editing.branch_prices.map((branch) => (
                                    <BranchPriceForm
                                        key={`${branch.branch_id}-${branch.price_override}-${branch.is_available}-${branch.tracks_inventory}-${branch.low_stock_threshold}`}
                                        product={editing}
                                        branch={branch}
                                    />
                                ))}
                            </div>
                        )}
                    </div>
                )}
            </CatalogDialog>
        </CatalogPage>
    );
}

function ProductCard({
    product,
    hasInventoryScope,
    onEdit,
}: {
    product: CatalogProduct;
    hasInventoryScope: boolean;
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
            className={`${ownerPanelClass} flex min-w-0 flex-col gap-3 p-3.5 ${product.is_active ? '' : 'opacity-75'}`}
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
                    tone={product.is_active ? 'green' : 'outline'}
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
            width={68}
            height={68}
            onError={() => setFailed(true)}
            className="size-[68px] shrink-0 rounded-xl bg-[#f2f2f2] object-cover"
        />
    ) : (
        <span className="flex size-[68px] shrink-0 items-center justify-center rounded-xl bg-[#f2f2f2] text-[#b5b5b5]">
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

    return (
        <form
            className="flex flex-wrap items-end gap-2"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(
                    index.url(),
                    { search, category, status },
                    { preserveScroll: true, preserveState: true },
                );
            }}
        >
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
            <Button
                type="submit"
                variant="outline"
                className={`${actionClass} flex-1 sm:flex-none`}
            >
                Apply filters
            </Button>
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
        </form>
    );
}
