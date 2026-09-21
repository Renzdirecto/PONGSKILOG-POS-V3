import { router, useForm, usePage } from '@inertiajs/react';
import { FolderTree, LayoutGrid, List, Pencil } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    ActiveField,
    CatalogDialog,
    CatalogPage,
    controlClass,
    FormErrors,
    SaveButton,
    TextField,
} from '@/components/catalog-ui';
import {
    CategoryIcon,
    categoryIconChoices,
} from '@/components/category-icon';
import { InventoryPagination } from '@/components/inventory-ui';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import { index, store, update } from '@/routes/categories';
import type { Category, CategoryIconKey } from '@/types/catalog';

type Filters = { search?: string; status?: string };
type Props = {
    categories: {
        data: Category[];
        total: number;
        current_page: number;
        last_page: number;
    };
    filters: Filters;
};
type ViewMode = 'tile' | 'list';

export default function Categories({ categories, filters }: Props) {
    const createRequested = usePage().url.includes('create=category');
    const [editing, setEditing] = useState<Category | null | undefined>(
        createRequested ? null : undefined,
    );
    const [viewMode, setViewMode] = useState<ViewMode>(() => {
        if (typeof window === 'undefined') return 'tile';
        return window.localStorage.getItem('owner-categories-view') === 'list'
            ? 'list'
            : 'tile';
    });

    useEffect(() => {
        window.localStorage.setItem('owner-categories-view', viewMode);
    }, [viewMode]);

    return (
        <CatalogPage
            tab="Categories"
            counts={{ Categories: categories.total }}
        >
            <CategoryFilters key={JSON.stringify(filters)} filters={filters} />
            <div className="flex items-center justify-between gap-2 text-[12px] text-[#666]">
                <p role="status">{categories.total} categories</p>
                <div
                    className="flex rounded-[10px] bg-[#ededed] p-1"
                    aria-label="Category view"
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
            {categories.data.length === 0 ? (
                <div className={`${ownerPanelClass} px-5 py-14 text-center`}>
                    <FolderTree className="mx-auto size-7 text-[#aaa]" />
                    <h2 className="mt-3 text-sm font-semibold">
                        No categories match
                    </h2>
                    <p className="mt-1 text-[12.5px] text-[#767676]">
                        Change the search or status filter, or add a category.
                    </p>
                </div>
            ) : (
                <ul
                    className={
                        viewMode === 'tile'
                            ? 'grid gap-2.5 sm:grid-cols-2 xl:grid-cols-3'
                            : `${ownerPanelClass} divide-y divide-[#eeeeee] overflow-hidden`
                    }
                >
                    {categories.data.map((category) => (
                        <CategoryRow
                            key={`${category.id}-${category.is_active}`}
                            category={category}
                            tile={viewMode === 'tile'}
                            onEdit={() => setEditing(category)}
                        />
                    ))}
                </ul>
            )}
            <InventoryPagination
                currentPage={categories.current_page}
                lastPage={categories.last_page}
                label="Category pagination"
                onPageChange={(page) =>
                    router.get(
                        index.url({ query: { ...filters, page } }),
                        {},
                        { preserveScroll: true, preserveState: true },
                    )
                }
            />
            <CatalogDialog
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? 'Edit category' : 'Add category'}
                description="Choose a reusable menu icon, sort order, and catalog state."
            >
                {editing !== undefined && (
                    <CategoryForm
                        key={editing?.id ?? 'new'}
                        category={editing}
                        onSaved={() => setEditing(undefined)}
                    />
                )}
            </CatalogDialog>
        </CatalogPage>
    );
}

function CategoryFilters({ filters }: { filters: Filters }) {
    const [search, setSearch] = useState(filters.search ?? '');
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
                { search, status },
                {
                    replace: true,
                    preserveScroll: true,
                    preserveState: true,
                    only: ['categories', 'filters'],
                    onStart: () => setLoading(true),
                    onFinish: () => setLoading(false),
                },
            );
        }, 350);

        return () => window.clearTimeout(timeout);
    }, [search, status]);

    return (
        <div className="flex flex-wrap gap-2" aria-busy={loading}>
            <input
                type="search"
                aria-label="Search categories"
                placeholder="Search categories"
                maxLength={255}
                value={search}
                className={`${controlClass} min-w-0 flex-1 basis-52`}
                onChange={(event) => setSearch(event.target.value)}
            />
            <select
                aria-label="Category status"
                value={status}
                className={`${controlClass} min-w-0 flex-1 basis-40 sm:flex-none`}
                onChange={(event) => setStatus(event.target.value)}
            >
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="inactive">Disabled</option>
            </select>
            <span
                role="status"
                className={`self-center px-2 text-[11px] text-[#767676] ${loading ? 'opacity-100' : 'opacity-0'}`}
            >
                Updating…
            </span>
        </div>
    );
}

function CategoryRow({
    category,
    tile,
    onEdit,
}: {
    category: Category;
    tile: boolean;
    onEdit: () => void;
}) {
    const form = useForm({
        name: category.name,
        icon_key: category.icon_key,
        sort_order: String(category.sort_order),
        is_active: !category.is_active,
    });
    const submitting = useRef(false);

    return (
        <li
            className={`${tile ? `${ownerPanelClass} p-3.5` : 'px-3.5 py-3 sm:px-4'} flex min-w-0 flex-wrap items-center gap-3`}
        >
            <span className="flex size-11 shrink-0 items-center justify-center rounded-[11px] bg-[#f2f2f2] text-[#555]">
                <CategoryIcon iconKey={category.icon_key} />
            </span>
            <div className="min-w-0 flex-1">
                <h2 className="text-[13.5px] font-semibold break-words">
                    {category.name}
                </h2>
                <p className="mt-0.5 text-[11.5px] text-[#767676]">
                    {category.products_count} products · Sort order{' '}
                    {category.sort_order}
                </p>
            </div>
            <OwnerStatusBadge tone={category.is_active ? 'green' : 'outline'}>
                {category.is_active ? 'Active' : 'Disabled'}
            </OwnerStatusBadge>
            <div
                className={`${tile ? 'grid w-full grid-cols-2' : 'flex'} gap-1.5`}
            >
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={onEdit}
                >
                    <Pencil className="size-3.5" /> Edit
                    <span className="sr-only"> {category.name}</span>
                </Button>
                <Button
                    variant="outline"
                    disabled={form.processing}
                    className={`${actionClass} ${category.is_active ? 'text-red-700 hover:text-red-800' : 'border-[#111111] bg-[#111111] text-white hover:bg-neutral-800 hover:text-white'}`}
                    onClick={() => {
                        if (submitting.current) return;
                        submitting.current = true;
                        form.submit(update(category.id), {
                            preserveScroll: true,
                            onSuccess: () =>
                                toast.success(
                                    `${category.name} ${category.is_active ? 'disabled' : 'enabled'}`,
                                ),
                            onFinish: () => {
                                submitting.current = false;
                            },
                        });
                    }}
                >
                    {form.processing
                        ? 'Saving…'
                        : category.is_active
                          ? 'Disable'
                          : 'Enable'}
                </Button>
            </div>
        </li>
    );
}

function CategoryForm({
    category,
    onSaved,
}: {
    category: Category | null;
    onSaved: () => void;
}) {
    const form = useForm({
        name: category?.name ?? '',
        icon_key: category?.icon_key ?? ('food' as CategoryIconKey),
        sort_order: String(category?.sort_order ?? 0),
        is_active: category?.is_active ?? true,
    });
    const submitting = useRef(false);

    return (
        <form
            className="flex flex-col gap-4"
            aria-busy={form.processing}
            onSubmit={(event) => {
                event.preventDefault();
                if (submitting.current) return;
                submitting.current = true;
                form.submit(category ? update(category.id) : store(), {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Category saved');
                        onSaved();
                    },
                    onFinish: () => {
                        submitting.current = false;
                    },
                });
            }}
        >
            <fieldset disabled={form.processing} className="space-y-4">
                <TextField
                    id="category-name"
                    label="Name"
                    value={form.data.name}
                    onChange={(value) => form.setData('name', value)}
                    error={form.errors.name}
                />
                <div className="space-y-2">
                    <p className="text-[12px] font-semibold">Category icon</p>
                    <div className="grid grid-cols-4 gap-2 sm:grid-cols-6">
                        {categoryIconChoices.map((choice) => (
                            <button
                                key={choice.key}
                                type="button"
                                aria-label={choice.label}
                                title={choice.label}
                                aria-pressed={
                                    form.data.icon_key === choice.key
                                }
                                onClick={() =>
                                    form.setData('icon_key', choice.key)
                                }
                                className={`flex min-h-12 items-center justify-center rounded-xl border ${form.data.icon_key === choice.key ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white text-[#555]'}`}
                            >
                                <CategoryIcon iconKey={choice.key} />
                            </button>
                        ))}
                    </div>
                    {form.errors.icon_key && (
                        <p className="text-xs text-red-700">
                            {form.errors.icon_key}
                        </p>
                    )}
                </div>
                <TextField
                    id="category-sort"
                    label="Sort order"
                    type="number"
                    value={form.data.sort_order}
                    onChange={(value) => form.setData('sort_order', value)}
                    error={form.errors.sort_order}
                />
                <ActiveField
                    value={form.data.is_active}
                    onChange={(value) => form.setData('is_active', value)}
                />
            </fieldset>
            <FormErrors errors={form.errors} />
            <SaveButton
                processing={form.processing}
                label={category ? 'Save changes' : 'Add category'}
            />
        </form>
    );
}
