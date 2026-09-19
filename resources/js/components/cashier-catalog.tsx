import { Search } from 'lucide-react';
import { useState } from 'react';
import { money } from '@/components/catalog-ui';
import { ProductImage } from '@/components/product-forms';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CashierCatalog as CashierCatalogData } from '@/types/catalog';

export function CashierCatalog({
    catalog,
    onSelect,
}: {
    catalog: CashierCatalogData;
    onSelect?: (product: CashierCatalogData['products'][number]) => void;
}) {
    const [search, setSearch] = useState('');
    const [categoryId, setCategoryId] = useState('');
    const searchTerm = search.trim().toLocaleLowerCase();
    const products = catalog.products.filter(
        (product) =>
            (!categoryId || product.category_id === categoryId) &&
            product.name.toLocaleLowerCase().includes(searchTerm),
    );

    return (
        <div className="flex flex-col gap-5">
            <div className="space-y-2">
                <Label htmlFor="catalog-search">Search products</Label>
                <div className="relative">
                    <Search
                        aria-hidden="true"
                        className="pointer-events-none absolute top-3.5 left-3.5 size-5 text-neutral-500"
                    />
                    <Input
                        id="catalog-search"
                        type="search"
                        placeholder="Search by product name"
                        value={search}
                        onChange={(event) => setSearch(event.target.value)}
                        className="h-12 rounded-xl border-neutral-300 bg-white pl-11 text-base"
                    />
                </div>
            </div>

            <nav
                aria-label="Catalog categories"
                className="flex flex-wrap gap-2"
            >
                {[{ id: '', name: 'All' }, ...catalog.categories].map(
                    (category) => (
                        <Button
                            key={category.id}
                            variant="outline"
                            aria-pressed={categoryId === category.id}
                            onClick={() => setCategoryId(category.id)}
                            className={`h-auto min-h-11 max-w-full rounded-xl px-4 wrap-anywhere whitespace-normal ${categoryId === category.id ? 'border-neutral-950 bg-neutral-950 text-white hover:bg-neutral-800 hover:text-white' : 'border-neutral-200 bg-white text-neutral-700 hover:bg-neutral-100 hover:text-neutral-950'}`}
                        >
                            {category.name}
                        </Button>
                    ),
                )}
            </nav>

            <p role="status" className="text-sm text-neutral-600">
                {products.length}{' '}
                {products.length === 1 ? 'product' : 'products'}
            </p>

            {products.length > 0 ? (
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-[repeat(auto-fill,minmax(160px,1fr))]">
                    {products.map((product) => (
                        <article
                            key={product.id}
                            className="min-w-0 overflow-hidden rounded-xl border border-neutral-200 bg-white"
                        >
                            <button
                                type="button"
                                disabled={!onSelect || !product.is_available}
                                onClick={() => onSelect?.(product)}
                                className="block w-full text-left focus-visible:outline-2 focus-visible:outline-offset-2 disabled:cursor-default"
                                aria-label={`Customize ${product.name}`}
                            >
                                <ProductImage product={product} />
                                <div className="flex flex-col items-start gap-2 p-3">
                                    <div className="w-full space-y-1">
                                        <p className="text-xs wrap-break-word text-neutral-500">
                                            {product.category_name}
                                        </p>
                                        <h3 className="text-sm font-bold wrap-break-word">
                                            {product.name}
                                        </h3>
                                        <p className="text-sm font-bold wrap-break-word text-red-700 tabular-nums">
                                            {money(product.effective_price)}
                                        </p>
                                    </div>
                                    <span
                                        className={`rounded-full px-2.5 py-1 text-xs font-semibold ${product.is_available ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                                    >
                                        {product.stock_status === 'out_of_stock'
                                            ? 'OUT OF STOCK'
                                            : product.is_available
                                              ? 'Available'
                                              : 'Unavailable'}
                                    </span>
                                    {product.stock_status === 'low_stock' && (
                                        <span className="rounded-full bg-amber-50 px-2.5 py-1 text-xs font-semibold text-amber-900">
                                            Low stock
                                        </span>
                                    )}
                                    {product.has_modifiers && (
                                        <p className="text-xs text-neutral-500">
                                            Options available
                                        </p>
                                    )}
                                </div>
                            </button>
                        </article>
                    ))}
                </div>
            ) : (
                <div className="rounded-2xl border border-dashed border-neutral-300 p-6 text-center">
                    <h3 className="font-bold">
                        {catalog.products.length === 0
                            ? 'No products available yet'
                            : 'No matching products'}
                    </h3>
                    <p className="mt-2 text-sm text-neutral-600">
                        {catalog.products.length === 0
                            ? 'The catalog will appear here when active products are added.'
                            : 'Try another product name or category.'}
                    </p>
                    {catalog.products.length > 0 && (
                        <Button
                            variant="outline"
                            className="mt-4 min-h-11 border-neutral-200 bg-white text-neutral-950 hover:bg-neutral-100 hover:text-neutral-950"
                            onClick={() => {
                                setSearch('');
                                setCategoryId('');
                            }}
                        >
                            Clear filters
                        </Button>
                    )}
                </div>
            )}
        </div>
    );
}
