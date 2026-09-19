import { Grid2X2, Search, X } from 'lucide-react';
import { useState } from 'react';
import { PosProductMedia } from '@/components/pos-product-media';
import { pesos } from '@/lib/pos-money';
import type { CashierCatalog as CashierCatalogData } from '@/types/catalog';
import type { CartLine } from '@/types/pos';

export function CashierCatalog({
    catalog,
    onSelect,
    lines = [],
    workspace = false,
}: {
    catalog: CashierCatalogData;
    onSelect?: (product: CashierCatalogData['products'][number]) => void;
    lines?: CartLine[];
    workspace?: boolean;
}) {
    const [search, setSearch] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const products = catalog.products.filter(
        (product) =>
            (!categoryId || product.category_id === categoryId) &&
            product.name
                .toLocaleLowerCase()
                .includes(search.trim().toLocaleLowerCase()),
    );
    return (
        <div
            className={`flex min-h-0 min-w-0 flex-col ${workspace ? 'flex-1' : ''}`}
        >
            <div className="flex shrink-0 flex-col gap-2.5 border-b border-neutral-200 bg-white px-3 py-2.5 lg:flex-row lg:items-center">
                <nav
                    aria-label="Catalog categories"
                    className="flex min-w-0 flex-1 [scrollbar-width:none] gap-[7px] overflow-x-auto p-px [&::-webkit-scrollbar]:hidden"
                >
                    {[{ id: '', name: 'All' }, ...catalog.categories].map(
                        (category) => (
                            <button
                                key={category.id}
                                aria-pressed={categoryId === category.id}
                                onClick={() => setCategoryId(category.id)}
                                className={`flex min-h-11 shrink-0 items-center gap-2 rounded-[11px] border px-3 text-xs font-semibold ${categoryId === category.id ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-200 bg-white text-neutral-600 hover:border-neutral-500'}`}
                            >
                                {!category.id && <Grid2X2 className="size-4" />}
                                {category.name}
                                <span
                                    className={`rounded-md px-1.5 py-0.5 text-[10px] ${categoryId === category.id ? 'bg-white/20' : 'bg-neutral-100'}`}
                                >
                                    {
                                        catalog.products.filter(
                                            (product) =>
                                                !category.id ||
                                                product.category_id ===
                                                    category.id,
                                        ).length
                                    }
                                </span>
                            </button>
                        ),
                    )}
                </nav>
                <div className="flex h-[46px] min-w-0 shrink-0 items-center gap-2 rounded-[11px] border border-neutral-200 bg-[#f7f7f7] pl-3 min-[1300px]:w-[270px] lg:w-[210px]">
                    <Search className="size-4 shrink-0 text-neutral-500" />
                    <input
                        aria-label="Search products"
                        placeholder="Search products"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="h-full min-w-0 flex-1 bg-transparent text-base outline-none"
                    />
                    {search && (
                        <button
                            aria-label="Clear search"
                            onClick={() => setSearch('')}
                            className="flex size-11 shrink-0 items-center justify-center"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </div>
            </div>
            <div
                className={`min-h-0 overflow-y-auto p-3 ${workspace ? 'flex-1 pb-24 md:pb-3' : ''}`}
            >
                <p role="status" className="sr-only">
                    {products.length} products
                </p>
                {products.length ? (
                    <div className="grid grid-cols-2 gap-2 min-[1400px]:grid-cols-[repeat(auto-fill,minmax(168px,1fr))] md:grid-cols-[repeat(auto-fill,minmax(150px,1fr))] md:gap-2.5 lg:grid-cols-[repeat(auto-fill,minmax(156px,1fr))]">
                        {products.map((product) => {
                            const count = lines
                                .filter(
                                    (line) => line.product.id === product.id,
                                )
                                .reduce((sum, line) => sum + line.quantity, 0);
                            return (
                                <button
                                    key={product.id}
                                    disabled={
                                        !onSelect || !product.is_available
                                    }
                                    onClick={() => onSelect?.(product)}
                                    aria-label={`Customize ${product.name}`}
                                    className="flex min-w-0 flex-col items-start gap-[7px] rounded-[14px] border border-neutral-200 bg-white p-2.5 text-left shadow-xs enabled:hover:border-neutral-950 enabled:active:scale-[.985] disabled:cursor-default"
                                >
                                    <span className="relative flex aspect-[3/2] w-full shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-[#f2f2f2]">
                                        <PosProductMedia product={product} />
                                        {count > 0 && (
                                            <span className="absolute top-1.5 right-1.5 rounded-full bg-neutral-950 px-1.5 py-1 text-[11px] font-bold text-white">
                                                ×{count}
                                            </span>
                                        )}
                                    </span>
                                    <span className="line-clamp-2 text-sm leading-[1.3] font-semibold wrap-anywhere">
                                        {product.name}
                                    </span>
                                    <span className="text-sm font-bold text-red-700 tabular-nums">
                                        {pesos(product.effective_price)}
                                    </span>
                                    {(!product.is_available ||
                                        product.stock_status ===
                                            'low_stock') && (
                                        <span className="rounded-full bg-neutral-100 px-2 py-1 text-[10px] font-semibold text-neutral-600">
                                            {product.stock_status ===
                                            'out_of_stock'
                                                ? 'Out of stock'
                                                : product.is_available
                                                  ? 'Low stock'
                                                  : 'Unavailable'}
                                        </span>
                                    )}
                                </button>
                            );
                        })}
                    </div>
                ) : (
                    <div className="flex flex-col items-center gap-3 px-5 py-14 text-center">
                        <Search className="size-10 rounded-xl bg-neutral-100 p-2 text-neutral-500" />
                        <h2 className="text-[15px] font-semibold">
                            {catalog.products.length
                                ? 'No products match'
                                : 'No products available yet'}
                        </h2>
                        <p className="text-xs text-neutral-500">
                            Try another product name or category.
                        </p>
                        <button
                            onClick={() => {
                                setSearch('');
                                setCategoryId('');
                            }}
                            className="min-h-11 rounded-xl border px-4 text-xs font-semibold"
                        >
                            Clear search &amp; filters
                        </button>
                    </div>
                )}
            </div>
        </div>
    );
}
