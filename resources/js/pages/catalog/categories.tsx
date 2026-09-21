import { useForm } from '@inertiajs/react';
import { FolderTree, Pencil, Plus } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    ActiveField,
    CatalogDialog,
    CatalogPage,
    FormErrors,
    primaryActionClass,
    SaveButton,
    TextField,
} from '@/components/catalog-ui';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import { store, update } from '@/routes/categories';
import type { Category } from '@/types/catalog';

export default function Categories({ categories }: { categories: Category[] }) {
    const [editing, setEditing] = useState<Category | null | undefined>();
    return (
        <CatalogPage
            tab="Categories"
            counts={{ Categories: categories.length }}
            action={
                <Button
                    className={`${primaryActionClass} w-full md:w-auto`}
                    onClick={() => setEditing(null)}
                >
                    <Plus className="size-4" /> Add category
                </Button>
            }
        >
            <p className="text-[12.5px] leading-5 text-[#666]">
                Inactive categories hide their products from the available
                catalog.
            </p>
            {categories.length === 0 ? (
                <div className={`${ownerPanelClass} px-5 py-14 text-center`}>
                    <FolderTree className="mx-auto size-7 text-[#aaa]" />
                    <h2 className="mt-3 text-sm font-semibold">
                        No categories yet
                    </h2>
                    <p className="mt-1 text-[12.5px] text-[#767676]">
                        Add a category before creating products.
                    </p>
                </div>
            ) : (
                <ul
                    className={`${ownerPanelClass} divide-y divide-[#eeeeee] overflow-hidden`}
                >
                    {categories.map((category) => (
                        <CategoryRow
                            key={`${category.id}-${category.is_active}`}
                            category={category}
                            onEdit={() => setEditing(category)}
                        />
                    ))}
                </ul>
            )}
            <CatalogDialog
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? 'Edit category' : 'Add category'}
                description="Organize your catalog and control category availability."
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

function CategoryRow({
    category,
    onEdit,
}: {
    category: Category;
    onEdit: () => void;
}) {
    const form = useForm({
        name: category.name,
        sort_order: String(category.sort_order),
        is_active: !category.is_active,
    });
    const submitting = useRef(false);

    return (
        <li className="flex min-w-0 flex-wrap items-center gap-3 px-3.5 py-3 sm:px-4">
            <span className="flex size-10 shrink-0 items-center justify-center rounded-[11px] bg-[#f2f2f2] text-[#666]">
                <FolderTree className="size-4" />
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
            <div className="flex gap-1.5">
                <Button
                    variant="outline"
                    className={actionClass}
                    onClick={onEdit}
                >
                    <Pencil className="size-3.5" /> Edit{' '}
                    <span className="sr-only">{category.name}</span>
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
                            onError: () =>
                                toast.error(
                                    `Unable to ${category.is_active ? 'disable' : 'enable'} ${category.name}`,
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
            <fieldset
                disabled={form.processing}
                className="flex flex-col gap-4"
            >
                <TextField
                    id="category-name"
                    label="Name"
                    value={form.data.name}
                    onChange={(value) => form.setData('name', value)}
                    error={form.errors.name}
                />
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
