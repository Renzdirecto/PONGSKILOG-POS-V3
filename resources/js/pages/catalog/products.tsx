import { Link, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    actionClass,
    CatalogDialog,
    CatalogPage,
    controlClass,
    money,
    panelClass,
    primaryActionClass,
    Status,
} from '@/components/catalog-ui';
import {
    BranchPriceForm,
    ProductForm,
    ProductImage,
    ProductImageForm,
} from '@/components/product-forms';
import { Button } from '@/components/ui/button';
import { index } from '@/routes/products';
import { index as categoriesIndex } from '@/routes/categories';
import type { CatalogChoice, CatalogProduct } from '@/types/catalog';

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

export default function Products({
    products,
    categories,
    modifierGroups,
    filters,
}: Props) {
    const [editing, setEditing] = useState<CatalogProduct | null | undefined>();
    const [imageProductId, setImageProductId] = useState<string | null>(null);
    const [branchProductId, setBranchProductId] = useState<string | null>(null);
    const imageProduct = products.data.find(
        (product) => product.id === imageProductId,
    );
    const branchProduct = products.data.find(
        (product) => product.id === branchProductId,
    );
    return (
        <CatalogPage
            tab="Products"
            action={
                <Button
                    className={primaryActionClass}
                    disabled={categories.length === 0}
                    onClick={() => setEditing(null)}
                >
                    Add product
                </Button>
            }
        >
            {categories.length === 0 && (
                <div className="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                    Create a category to start adding products.{' '}
                    <Link
                        href={categoriesIndex()}
                        className="font-semibold underline"
                    >
                        Manage categories
                    </Link>
                </div>
            )}
            <ProductFilters
                key={JSON.stringify(filters)}
                filters={filters}
                categories={categories}
            />
            <p className="text-sm text-neutral-500">
                {products.total} products · Global catalog
            </p>
            {products.data.length === 0 ? (
                <div className={`${panelClass} py-12 text-center`}>
                    <h2 className="text-lg font-bold">No products found</h2>
                    <p className="mt-2 text-sm text-neutral-500">
                        Add your first product or change the filters.
                    </p>
                </div>
            ) : (
                <ul className="grid gap-5 sm:grid-cols-2 lg:grid-cols-3">
                    {products.data.map((product) => (
                        <li
                            key={product.id}
                            className="flex min-w-0 flex-col overflow-hidden rounded-2xl border border-neutral-200 bg-white shadow-sm"
                        >
                            <ProductImage product={product} />
                            <div className="flex flex-1 flex-col gap-4 p-5">
                                <div className="flex items-start justify-between gap-3">
                                    <div className="min-w-0">
                                        <p className="text-xs font-semibold text-neutral-500">
                                            {product.category_name}
                                            {!product.category_active &&
                                                ' · Inactive category'}
                                        </p>
                                        <h2 className="mt-1 text-xl font-bold break-words">
                                            {product.name}
                                        </h2>
                                    </div>
                                    <Status active={product.is_active} />
                                </div>
                                <p className="text-lg font-bold">
                                    {money(product.default_price)}{' '}
                                    <span className="text-xs font-normal text-neutral-500">
                                        default
                                    </span>
                                </p>
                                <div className="flex flex-col gap-2 rounded-xl bg-neutral-50 p-3">
                                    {product.branch_prices.length === 0 ? (
                                        <p className="text-xs text-neutral-500">
                                            No branches configured.
                                        </p>
                                    ) : (
                                        product.branch_prices.map((branch) => (
                                            <div
                                                key={branch.branch_id}
                                                className="flex flex-wrap justify-between gap-1 text-sm"
                                            >
                                                <span className="font-semibold">
                                                    {branch.code}
                                                </span>
                                                <span className="text-neutral-600">
                                                    {branch.price_override ===
                                                        null && (
                                                        <span className="text-neutral-400">
                                                            Default{' '}
                                                        </span>
                                                    )}
                                                    {money(
                                                        branch.effective_price,
                                                    )}
                                                    {!branch.effective_available &&
                                                        ' · Unavailable'}
                                                </span>
                                            </div>
                                        ))
                                    )}
                                </div>
                                <div className="mt-auto grid grid-cols-2 gap-2">
                                    <Button
                                        variant="outline"
                                        className={actionClass}
                                        onClick={() => setEditing(product)}
                                    >
                                        Edit product
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className={actionClass}
                                        onClick={() =>
                                            setImageProductId(product.id)
                                        }
                                    >
                                        Image
                                    </Button>
                                    <Button
                                        variant="outline"
                                        className={`col-span-2 ${actionClass}`}
                                        disabled={
                                            product.branch_prices.length === 0
                                        }
                                        onClick={() =>
                                            setBranchProductId(product.id)
                                        }
                                    >
                                        Branch overrides
                                    </Button>
                                </div>
                            </div>
                        </li>
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
                    <span className="text-sm">
                        Page {products.current_page} of {products.last_page}
                    </span>
                    <Button
                        variant="outline"
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
                title={editing ? 'Edit product' : 'Add product'}
                description="Set product details, default price, and modifier groups."
            >
                {editing !== undefined && (
                    <ProductForm
                        key={editing?.id ?? 'new'}
                        product={editing}
                        categories={categories}
                        groups={modifierGroups}
                        onSaved={() => setEditing(undefined)}
                    />
                )}
            </CatalogDialog>
            <CatalogDialog
                open={!!imageProduct}
                onClose={() => setImageProductId(null)}
                title={`Image · ${imageProduct?.name ?? ''}`}
                description="Manage the image shown in your product catalog."
            >
                {imageProduct && (
                    <ProductImageForm
                        key={imageProduct.id}
                        product={imageProduct}
                        onSaved={() => setImageProductId(null)}
                    />
                )}
            </CatalogDialog>
            <CatalogDialog
                open={!!branchProduct}
                onClose={() => setBranchProductId(null)}
                title={`Branch overrides · ${branchProduct?.name ?? ''}`}
                description="Configure pricing, availability, and inventory settings for each branch."
            >
                {branchProduct && (
                    <div className="flex flex-col gap-4">
                        {branchProduct.branch_prices.map((branch) => (
                            <BranchPriceForm
                                key={`${branch.branch_id}-${branch.price_override}-${branch.is_available}-${branch.tracks_inventory}-${branch.low_stock_threshold}`}
                                product={branchProduct}
                                branch={branch}
                            />
                        ))}
                    </div>
                )}
            </CatalogDialog>
        </CatalogPage>
    );
}

function ProductFilters({
    filters,
    categories,
}: {
    filters: Filters;
    categories: CatalogChoice[];
}) {
    const [search, setSearch] = useState(filters.search ?? '');
    const [category, setCategory] = useState(filters.category ?? '');
    const [status, setStatus] = useState(filters.status ?? '');
    return (
        <form
            className="grid gap-3 sm:grid-cols-2 lg:grid-cols-[2fr_1fr_1fr_auto]"
            onSubmit={(event) => {
                event.preventDefault();
                router.get(
                    index.url(),
                    { search, category, status },
                    { preserveScroll: true, preserveState: true },
                );
            }}
        >
            <label className="space-y-1 text-xs font-semibold">
                Search products
                <input
                    className={controlClass}
                    value={search}
                    maxLength={255}
                    placeholder="Search by name…"
                    onChange={(event) => setSearch(event.target.value)}
                />
            </label>
            <label className="space-y-1 text-xs font-semibold">
                Category
                <select
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
            <label className="space-y-1 text-xs font-semibold">
                Status
                <select
                    className={controlClass}
                    value={status}
                    onChange={(event) => setStatus(event.target.value)}
                >
                    <option value="">All statuses</option>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                </select>
            </label>
            <Button
                type="submit"
                variant="outline"
                className={`self-end ${actionClass}`}
            >
                Apply filters
            </Button>
        </form>
    );
}
