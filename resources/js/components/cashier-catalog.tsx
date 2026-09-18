import { Search } from 'lucide-react';
import { useState } from 'react';
import { money } from '@/components/catalog-ui';
import { ProductImage } from '@/components/product-forms';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import type { CashierCatalog as CashierCatalogData } from '@/types/catalog';

export function CashierCatalog({ catalog }: { catalog: CashierCatalogData }) {
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
                <div className="grid grid-cols-1 gap-2 min-[390px]:grid-cols-2 sm:gap-4 lg:grid-cols-3 xl:grid-cols-4">
                    {products.map((product) => (
                        <article
                            key={product.id}
                            className="min-w-0 overflow-hidden rounded-2xl border border-neutral-200 bg-white"
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
                                    <p className="text-sm font-bold wrap-break-word tabular-nums">
                                        {money(product.effective_price)}
                                    </p>
                                </div>
                                <span
                                    className={`rounded-full px-2.5 py-1 text-xs font-semibold ${product.is_available ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-red-800'}`}
                                >
                                    {product.is_available
                                        ? 'Available'
                                        : 'Unavailable'}
                                </span>
                                {product.has_modifiers && (
                                    <p className="text-xs text-neutral-500">
                                        Options available
                                    </p>
                                )}
                            </div>
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
