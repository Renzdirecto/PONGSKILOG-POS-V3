import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    ActiveField,
    CatalogDialog,
    CatalogPage,
    FormErrors,
    panelClass,
    primaryActionClass,
    SaveButton,
    Status,
    TextField,
} from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import { store, update } from '@/routes/categories';
import type { Category } from '@/types/catalog';

export default function Categories({ categories }: { categories: Category[] }) {
    const [editing, setEditing] = useState<Category | null | undefined>();
    return (
        <CatalogPage
            tab="Categories"
            action={
                <Button
                    className={primaryActionClass}
                    onClick={() => setEditing(null)}
                >
                    Add category
                </Button>
            }
        >
            <p className="text-sm text-neutral-600">
                Inactive categories hide their products from the available
                catalog.
            </p>
            {categories.length === 0 ? (
                <div className={panelClass}>
                    No categories yet. Add a category before creating products.
                </div>
            ) : (
                <ul className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                    {categories.map((category) => (
                        <li
                            key={category.id}
                            className={`${panelClass} flex flex-col gap-4`}
                        >
                            <div className="flex items-start justify-between gap-3">
                                <h2 className="min-w-0 text-lg font-bold break-words">
                                    {category.name}
                                </h2>
                                <Status active={category.is_active} />
                            </div>
                            <p className="text-sm text-neutral-500">
                                {category.products_count} products · Sort order{' '}
                                {category.sort_order}
                            </p>
                            <Button
                                variant="outline"
                                className={actionClass}
                                onClick={() => setEditing(category)}
                            >
                                Edit{' '}
                                <span className="sr-only">{category.name}</span>
                            </Button>
                        </li>
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
