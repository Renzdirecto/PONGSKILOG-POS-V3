import { Head, Link, router, usePage } from '@inertiajs/react';
import {
    Boxes,
    History,
    ImageIcon,
    PackageSearch,
    SlidersHorizontal,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    actionClass,
    controlClass,
    primaryActionClass,
} from '@/components/catalog-ui';
import { InventoryAdjustmentDialog } from '@/components/inventory-adjustment-dialog';
import {
    Chip,
    IngredientIcon,
    Segmented,
    StatusChip,
    formatQuantityOrDash,
    operationsHref,
} from '@/components/operations-ui';
import {
    InventoryPagination,
    StockStatusBadge,
    stockStatusLabels,
} from '@/components/inventory-ui';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerPanelClass,
} from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { index } from '@/routes/inventory';
import { index as productsIndex } from '@/routes/products';
import type { Auth, BranchContext, BranchSummary } from '@/types';
import type { OperationsIngredient } from '@/types/operations';
import type {
    InventoryFilters,
    InventoryPagination as Paginated,
    InventoryProduct,
    InventorySummary,
    StockStatus,
    InventoryMovement,
} from '@/types/inventory';

type Category = { id: string; name: string };
type Props = {
    branches: BranchSummary[];
    selectedBranch: BranchSummary | null;
    filters: InventoryFilters;
    categories: Category[];
    summary: InventorySummary;
    products: Paginated<InventoryProduct>;
    ingredients: OperationsIngredient[];
    ingredientCount: number;
    usesGlobalBranch: boolean;
    history: {
        branch: BranchSummary;
        product: { id: string; name: string };
        movements: Paginated<InventoryMovement>;
    } | null;
};

const updatedDate = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});
const movementDate = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

const summaryCards: {
    status: StockStatus;
    label: string;
    tone: 'green' | 'amber' | 'red' | 'neutral';
}[] = [
    { status: 'in_stock', label: 'In stock', tone: 'green' },
    { status: 'low_stock', label: 'Low stock', tone: 'amber' },
    { status: 'out_of_stock', label: 'Out of stock', tone: 'red' },
    { status: 'not_tracked', label: 'Not tracked', tone: 'neutral' },
];

export default function Inventory(props: Props) {
    const { selectedBranch, filters, summary, products } = props;
    const { auth } = usePage<{
        auth: Auth;
        branchContext: BranchContext;
    }>().props;
    const [adjustingProductId, setAdjustingProductId] = useState<string | null>(
        null,
    );
    const adjustingProduct = products.data.find(
        (product) => product.id === adjustingProductId,
    );

    const visit = (
        changes: Partial<
            InventoryFilters & {
                branch_id: string;
                page: number;
                history_product: string;
                history_page: number;
            }
        >,
    ) => {
        router.get(
            index.url(),
            {
                branch_id: selectedBranch?.id,
                type: filters.type,
                search: filters.search,
                category: filters.category,
                stock_status: filters.stock_status,
                history_product: props.history?.product.id,
                ...changes,
            },
            { preserveScroll: true, preserveState: true },
        );
    };

    return (
        <>
            <Head title="Inventory" />
            <OwnerPage
                title="Inventory"
                description="Monitor branch stock, find shortages quickly, and keep an auditable adjustment history."
                action={
                    auth.permissions.includes('products.manage') ? (
                        <Link
                            href={productsIndex()}
                            className={`${actionClass} inline-flex w-full items-center justify-center gap-2 md:w-auto`}
                        >
                            <Boxes className="size-4" /> Product management
                        </Link>
                    ) : undefined
                }
            >
                {selectedBranch ? (
                    <>
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <p className="text-[13px] font-semibold">
                                Inventory for {selectedBranch.name} (
                                {selectedBranch.code})
                            </p>
                            {props.usesGlobalBranch && (
                                <OwnerStatusBadge tone="neutral">
                                    Global branch scope
                                </OwnerStatusBadge>
                            )}
                        </div>
                        <div className="max-w-[560px]">
                            <Segmented
                                label="Inventory type"
                                value={filters.type ?? 'all'}
                                onChange={(type) =>
                                    visit({
                                        type,
                                        page: 1,
                                        stock_status: 'all',
                                    })
                                }
                                options={[
                                    {
                                        value: 'all',
                                        label: `All · ${products.total + props.ingredientCount}`,
                                    },
                                    {
                                        value: 'products',
                                        label: 'Products',
                                    },
                                    {
                                        value: 'ingredients',
                                        label: `Ingredients · ${props.ingredientCount}`,
                                    },
                                ]}
                            />
                        </div>
                        {filters.type !== 'ingredients' && (
                            <>
                                <section
                                    aria-label="Inventory summary"
                                    className="grid grid-cols-2 gap-2 lg:grid-cols-4"
                                >
                                    {summaryCards.map((card) => {
                                        const active =
                                            filters.stock_status ===
                                            card.status;
                                        return (
                                            <button
                                                key={card.status}
                                                type="button"
                                                aria-pressed={active}
                                                onClick={() =>
                                                    visit({
                                                        stock_status: active
                                                            ? 'all'
                                                            : card.status,
                                                        page: 1,
                                                    })
                                                }
                                                className={`${ownerPanelClass} min-h-[92px] p-3 text-left transition hover:border-[#bdbdbd] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${active ? 'border-[#111] ring-1 ring-[#111]' : ''}`}
                                            >
                                                <OwnerStatusBadge
                                                    tone={card.tone}
                                                >
                                                    {card.label}
                                                </OwnerStatusBadge>
                                                <p className="mt-2 text-[24px] leading-none font-bold tabular-nums">
                                                    {summary[
                                                        card.status
                                                    ].toLocaleString()}
                                                </p>
                                            </button>
                                        );
                                    })}
                                </section>
                                <InventoryFiltersForm {...props} />
                                <div className="flex flex-wrap items-center justify-between gap-2 text-[11.5px] text-[#767676]">
                                    <p role="status">
                                        {products.total} products ·{' '}
                                        {selectedBranch.name} (
                                        {selectedBranch.code})
                                    </p>
                                    <p>
                                        Thresholds are configured in Product
                                        management.
                                    </p>
                                </div>
                                {products.data.length === 0 ? (
                                    <div
                                        className={`${ownerPanelClass} px-5 py-14 text-center`}
                                    >
                                        <PackageSearch className="mx-auto size-7 text-[#aaa]" />
                                        <h2 className="mt-3 text-sm font-semibold">
                                            No products found
                                        </h2>
                                        <p className="mt-1 text-[12.5px] text-[#767676]">
                                            Change the search or filters to view
                                            other stock.
                                        </p>
                                    </div>
                                ) : (
                                    <div
                                        className={`${ownerPanelClass} overflow-hidden`}
                                    >
                                        <div className="hidden grid-cols-[minmax(240px,1.6fr)_120px_100px_155px_210px] items-center gap-3 border-b border-[#e8e8e8] bg-[#fafafa] px-4 py-2.5 text-[10px] font-semibold tracking-[0.06em] text-[#767676] uppercase min-[980px]:grid">
                                            <span>Product</span>
                                            <span>Status</span>
                                            <span>On hand</span>
                                            <span>Last updated</span>
                                            <span className="text-right">
                                                Actions
                                            </span>
                                        </div>
                                        <ul className="divide-y divide-[#eeeeee]">
                                            {products.data.map((product) => (
                                                <InventoryRow
                                                    key={product.id}
                                                    product={product}
                                                    onAdjust={() =>
                                                        setAdjustingProductId(
                                                            product.id,
                                                        )
                                                    }
                                                    onHistory={() =>
                                                        visit({
                                                            history_product:
                                                                product.id,
                                                            history_page: 1,
                                                        })
                                                    }
                                                />
                                            ))}
                                        </ul>
                                    </div>
                                )}
                                <InventoryPagination
                                    currentPage={products.current_page}
                                    lastPage={products.last_page}
                                    label="Inventory pagination"
                                    onPageChange={(page) => visit({ page })}
                                />
                            </>
                        )}
                        {filters.type !== 'products' && (
                            <IngredientInventory
                                canOpenOperations={auth.permissions.includes(
                                    'operations.manage',
                                )}
                                ingredients={props.ingredients}
                            />
                        )}
                    </>
                ) : (
                    <>
                        <InventoryFiltersForm {...props} />
                        <div
                            className={`${ownerPanelClass} px-5 py-14 text-center`}
                        >
                            <PackageSearch className="mx-auto size-7 text-[#aaa]" />
                            <h2 className="mt-3 text-sm font-semibold">
                                {props.branches.length
                                    ? 'Choose a branch'
                                    : 'No branches'}
                            </h2>
                            <p className="mt-1 text-[12.5px] text-[#767676]">
                                {props.branches.length
                                    ? 'Inventory quantities are branch-specific and are never aggregated across All Branches.'
                                    : 'Inventory becomes available after a branch is added.'}
                            </p>
                        </div>
                    </>
                )}
                {adjustingProduct && selectedBranch && (
                    <InventoryAdjustmentDialog
                        key={`${selectedBranch.id}-${adjustingProduct.id}`}
                        product={adjustingProduct}
                        branch={selectedBranch}
                        onClose={() => setAdjustingProductId(null)}
                    />
                )}
                {props.history && (
                    <InventoryHistoryDialog
                        history={props.history}
                        onClose={() =>
                            visit({
                                history_product: undefined,
                                history_page: undefined,
                            })
                        }
                        onPageChange={(historyPage) =>
                            visit({ history_page: historyPage })
                        }
                    />
                )}
            </OwnerPage>
        </>
    );
}

/**
 * Ingredient rows read the same canonical Branch balance as Operations › Ingredient Stock. Changes are recorded there
 * (wastage, count correction, pamamalengke), never edited here. The Operations link shows only with Operations access.
 */
function IngredientInventory({
    ingredients,
    canOpenOperations,
}: {
    ingredients: OperationsIngredient[];
    canOpenOperations: boolean;
}) {
    return (
        <section
            aria-labelledby="inventory-ingredients"
            className={`${ownerPanelClass} overflow-hidden`}
        >
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-[#e8e8e8] bg-[#fafafa] px-4 py-2.5">
                <h2
                    id="inventory-ingredients"
                    className="text-[13px] font-semibold"
                >
                    Ingredients · {ingredients.length}
                </h2>
                {canOpenOperations && (
                    <Link
                        href={operationsHref('stock')}
                        className="text-[12px] font-semibold underline"
                    >
                        Adjust in Operations › Ingredient Stock
                    </Link>
                )}
            </div>
            {ingredients.length === 0 ? (
                <p className="px-4 py-8 text-center text-[12.5px] text-[#767676]">
                    No ingredients match. Ingredients are managed in Operations.
                </p>
            ) : (
                <ul className="divide-y divide-[#eeeeee]">
                    {ingredients.map((ingredient) => (
                        <li
                            key={ingredient.id}
                            className="grid min-w-0 gap-2 px-3.5 py-3 min-[980px]:grid-cols-[minmax(240px,1.6fr)_140px_150px_150px] min-[980px]:items-center min-[980px]:px-4"
                        >
                            <div className="flex min-w-0 items-center gap-3">
                                <IngredientIcon icon={ingredient.icon} />
                                <div className="min-w-0">
                                    <p className="truncate text-[13px] font-semibold">
                                        {ingredient.name}
                                    </p>
                                    <p className="mt-0.5 flex items-center gap-1.5 text-[11.5px] text-[#767676]">
                                        <Chip tone="outline">Ingredient</Chip>
                                        Base unit {ingredient.base_unit}
                                    </p>
                                </div>
                            </div>
                            <div>
                                <StatusChip ingredient={ingredient} />
                            </div>
                            <div className="flex items-baseline justify-between min-[980px]:block">
                                <span className="text-[11px] text-[#767676] min-[980px]:hidden">
                                    On hand
                                </span>
                                <span className="text-sm font-bold tabular-nums">
                                    {formatQuantityOrDash(
                                        ingredient.stock?.current,
                                        ingredient.base_unit,
                                    )}
                                </span>
                            </div>
                            <div className="flex items-baseline justify-between min-[980px]:block">
                                <span className="text-[11px] text-[#767676] min-[980px]:hidden">
                                    Target
                                </span>
                                <span className="text-[12.5px] text-[#555] tabular-nums">
                                    {formatQuantityOrDash(
                                        ingredient.target,
                                        ingredient.base_unit,
                                    )}
                                </span>
                            </div>
                        </li>
                    ))}
                </ul>
            )}
        </section>
    );
}

function InventoryRow({
    product,
    onAdjust,
    onHistory,
}: {
    product: InventoryProduct;
    onAdjust: () => void;
    onHistory: () => void;
}) {
    return (
        <li className="grid min-w-0 gap-3 px-3.5 py-3 min-[980px]:grid-cols-[minmax(240px,1.6fr)_120px_100px_155px_210px] min-[980px]:items-center min-[980px]:px-4">
            <div className="flex min-w-0 items-center gap-3">
                <InventoryThumbnail product={product} />
                <div className="min-w-0">
                    <h2 className="truncate text-[13px] font-semibold">
                        {product.name}
                    </h2>
                    <p className="mt-0.5 truncate text-[11.5px] text-[#767676]">
                        {product.category_name}
                    </p>
                </div>
            </div>
            <div>
                <StockStatusBadge status={product.status} />
            </div>
            <div className="flex items-baseline justify-between min-[980px]:block">
                <span className="text-[11px] text-[#767676] min-[980px]:hidden">
                    On hand
                </span>
                <span className="text-sm font-bold tabular-nums">
                    {product.on_hand?.toLocaleString() ?? '—'}
                </span>
            </div>
            <div className="flex items-baseline justify-between gap-2 text-[11.5px] min-[980px]:block">
                <span className="text-[#767676] min-[980px]:hidden">
                    Last updated
                </span>
                <span className="text-right text-[#555] min-[980px]:text-left">
                    {product.last_updated_at
                        ? updatedDate.format(new Date(product.last_updated_at))
                        : 'No stock update'}
                </span>
            </div>
            <div className="grid grid-cols-2 gap-1.5 min-[980px]:flex min-[980px]:justify-end">
                <Button
                    className={primaryActionClass}
                    disabled={!product.tracked}
                    onClick={onAdjust}
                >
                    <SlidersHorizontal className="size-3.5" />{' '}
                    {product.tracked ? 'Adjust' : 'Not tracked'}
                </Button>
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={onHistory}
                >
                    <History className="size-3.5" /> History
                </Button>
            </div>
        </li>
    );
}

function InventoryHistoryDialog({
    history,
    onClose,
    onPageChange,
}: {
    history: NonNullable<Props['history']>;
    onClose: () => void;
    onPageChange: (page: number) => void;
}) {
    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="owner-surface top-auto bottom-0 max-h-[92dvh] w-full max-w-none translate-y-0 overflow-y-auto rounded-t-[20px] rounded-b-none border-[#e5e5e5] bg-white p-4 sm:top-1/2 sm:bottom-auto sm:max-w-4xl sm:-translate-y-1/2 sm:rounded-[20px] sm:p-6">
                <DialogHeader className="pr-7 text-left">
                    <DialogTitle className="text-[17px] font-bold">
                        Inventory history
                    </DialogTitle>
                    <DialogDescription className="text-[12.5px] text-[#666]">
                        {history.product.name} · {history.branch.name} (
                        {history.branch.code})
                    </DialogDescription>
                </DialogHeader>
                {history.movements.data.length === 0 ? (
                    <div className="rounded-xl border border-[#e5e5e5] px-5 py-12 text-center">
                        <History className="mx-auto size-7 text-[#aaa]" />
                        <p className="mt-3 text-sm font-semibold">
                            No stock movements yet
                        </p>
                    </div>
                ) : (
                    <ol className="divide-y divide-[#eeeeee] overflow-hidden rounded-xl border border-[#e5e5e5]">
                        {history.movements.data.map((movement) => (
                            <li
                                key={movement.id}
                                className="grid min-w-0 gap-2 p-3 sm:grid-cols-[150px_minmax(150px,1fr)_minmax(200px,1.5fr)_90px] sm:items-center"
                            >
                                <div>
                                    <p className="text-[12.5px] font-semibold">
                                        {movement.movement_label}
                                    </p>
                                    <time
                                        dateTime={movement.created_at}
                                        className="text-[10.5px] text-[#767676]"
                                    >
                                        {movementDate.format(
                                            new Date(movement.created_at),
                                        )}
                                    </time>
                                </div>
                                <p className="text-[11.5px]">
                                    <span className="block text-[#767676]">
                                        Actor
                                    </span>
                                    {movement.created_by_name ??
                                        'Not available'}
                                </p>
                                <p className="text-[11.5px] break-words whitespace-pre-wrap">
                                    <span className="block text-[#767676]">
                                        Reason
                                    </span>
                                    {movement.reason ?? 'No reason recorded'}
                                </p>
                                <p
                                    className={`w-fit rounded-lg px-3 py-2 text-sm font-bold tabular-nums sm:justify-self-end ${movement.quantity_delta > 0 ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                                >
                                    {movement.quantity_delta > 0 ? '+' : ''}
                                    {movement.quantity_delta.toLocaleString()}
                                </p>
                            </li>
                        ))}
                    </ol>
                )}
                <InventoryPagination
                    currentPage={history.movements.current_page}
                    lastPage={history.movements.last_page}
                    label="Inventory history pagination"
                    onPageChange={onPageChange}
                />
            </DialogContent>
        </Dialog>
    );
}

function InventoryThumbnail({ product }: { product: InventoryProduct }) {
    const [failed, setFailed] = useState(false);
    return product.image_url && !failed ? (
        <img
            src={product.image_url}
            alt=""
            loading="lazy"
            width={144}
            height={96}
            onError={() => setFailed(true)}
            className="h-24 w-36 shrink-0 rounded-xl bg-[#f2f2f2] object-cover"
        />
    ) : (
        <span className="flex h-24 w-36 shrink-0 items-center justify-center rounded-xl bg-[#f2f2f2] text-[#aaa]">
            <ImageIcon className="size-5" />
        </span>
    );
}

function InventoryFiltersForm({
    branches,
    selectedBranch,
    filters,
    categories,
    usesGlobalBranch,
}: Props) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    const [stockStatus, setStockStatus] = useState(
        filters.stock_status ?? 'all',
    );
    const [loading, setLoading] = useState(false);
    const firstRender = useRef(true);
    const applyFilters = (branchId = selectedBranch?.id ?? '') => {
        setLoading(true);
        router.get(
            index.url(),
            {
                branch_id: branchId,
                search,
                category,
                stock_status: stockStatus,
            },
            {
                replace: true,
                preserveScroll: true,
                preserveState: true,
                onFinish: () => setLoading(false),
            },
        );
    };

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;
            return;
        }

        const timeout = window.setTimeout(() => applyFilters(), 350);

        return () => window.clearTimeout(timeout);
    }, [category, search, stockStatus]);

    return (
        <div aria-busy={loading} className={`${ownerPanelClass} p-3`}>
            <fieldset
                disabled={loading}
                className={`grid min-w-0 gap-2 sm:grid-cols-2 ${usesGlobalBranch ? 'md:grid-cols-[minmax(220px,1fr)_180px_170px]' : 'min-[900px]:grid-cols-[180px_minmax(220px,1fr)_180px_170px]'}`}
            >
                {!usesGlobalBranch && (
                    <label>
                        <span className="sr-only">Inventory branch</span>
                        <select
                            aria-label="Inventory branch"
                            className={controlClass}
                            value={selectedBranch?.id ?? ''}
                            onChange={(event) =>
                                applyFilters(event.target.value)
                            }
                        >
                            <option value="">Choose branch</option>
                            {branches.map((branch) => (
                                <option key={branch.id} value={branch.id}>
                                    {branch.name} ({branch.code})
                                </option>
                            ))}
                        </select>
                    </label>
                )}
                <label>
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
                <label>
                    <span className="sr-only">Category</span>
                    <select
                        aria-label="Category"
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
                <label>
                    <span className="sr-only">Stock status</span>
                    <select
                        aria-label="Stock status"
                        className={controlClass}
                        value={stockStatus}
                        onChange={(event) =>
                            setStockStatus(
                                event.target.value as StockStatus | 'all',
                            )
                        }
                    >
                        <option value="all">All stock</option>
                        {Object.entries(stockStatusLabels).map(
                            ([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ),
                        )}
                    </select>
                </label>
            </fieldset>
            <p
                role="status"
                className={`mt-2 text-[11px] text-[#767676] ${loading ? 'opacity-100' : 'opacity-0'}`}
            >
                Updating inventory…
            </p>
        </div>
    );
}
