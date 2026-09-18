import { useForm } from '@inertiajs/react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    ActiveField,
    controlClass,
    Field,
    FormErrors,
    money,
    SaveButton,
    TextField,
} from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import { store, update } from '@/routes/products';
import { update as updateBranch } from '@/routes/products/branches';
import {
    store as uploadImage,
    destroy as removeImage,
} from '@/routes/products/image';
import type {
    BranchPrice,
    CatalogChoice,
    CatalogProduct,
} from '@/types/catalog';

export function ProductForm({
    product,
    categories,
    groups,
    onSaved,
}: {
    product: CatalogProduct | null;
    categories: CatalogChoice[];
    groups: CatalogChoice[];
    onSaved: () => void;
}) {
    const form = useForm({
        name: product?.name ?? '',
        description: product?.description ?? '',
        category_id: product?.category_id ?? '',
        default_price: product?.default_price ?? '',
        is_active: product?.is_active ?? true,
        modifier_group_ids: product?.modifier_group_ids ?? [],
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
                form.submit(product ? update(product.id) : store(), {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Product saved');
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
                    id="product-name"
                    label="Product name"
                    value={form.data.name}
                    onChange={(value) => form.setData('name', value)}
                    error={form.errors.name}
                />
                <Field
                    id="product-category"
                    label="Category"
                    error={form.errors.category_id}
                >
                    <select
                        id="product-category"
                        required
                        className={controlClass}
                        value={form.data.category_id}
                        aria-invalid={!!form.errors.category_id}
                        onChange={(event) =>
                            form.setData('category_id', event.target.value)
                        }
                    >
                        <option value="">Select category</option>
                        {categories.map((category) => (
                            <option key={category.id} value={category.id}>
                                {category.name}
                                {!category.is_active ? ' (inactive)' : ''}
                            </option>
                        ))}
                    </select>
                </Field>
                <TextField
                    id="product-price"
                    label="Default price (₱)"
                    value={form.data.default_price}
                    onChange={(value) => form.setData('default_price', value)}
                    error={form.errors.default_price}
                />
                <Field
                    id="product-description"
                    label="Description (optional)"
                    error={form.errors.description}
                >
                    <textarea
                        id="product-description"
                        rows={3}
                        maxLength={5000}
                        className={`${controlClass} h-auto py-3`}
                        value={form.data.description}
                        onChange={(event) =>
                            form.setData('description', event.target.value)
                        }
                    />
                </Field>
                <ActiveField
                    value={form.data.is_active}
                    onChange={(value) => form.setData('is_active', value)}
                />
                <fieldset className="rounded-xl border border-neutral-200 p-3">
                    <legend className="px-1 text-sm font-semibold">
                        Modifier groups
                    </legend>
                    {groups.length === 0 ? (
                        <p className="text-sm text-neutral-500">
                            Create groups in Modifiers to add customization.
                        </p>
                    ) : (
                        groups.map((group) => (
                            <ActiveField
                                key={group.id}
                                label={`${group.name}${group.is_active ? '' : ' (inactive)'}`}
                                value={form.data.modifier_group_ids.includes(
                                    group.id,
                                )}
                                onChange={(checked) =>
                                    form.setData(
                                        'modifier_group_ids',
                                        checked
                                            ? [
                                                  ...form.data
                                                      .modifier_group_ids,
                                                  group.id,
                                              ]
                                            : form.data.modifier_group_ids.filter(
                                                  (id) => id !== group.id,
                                              ),
                                    )
                                }
                            />
                        ))
                    )}
                </fieldset>
                {!product && (
                    <p className="text-sm text-neutral-500">
                        After saving, add an image and configure branch
                        overrides from the product card.
                    </p>
                )}
            </fieldset>
            <FormErrors errors={form.errors} />
            <SaveButton
                processing={form.processing}
                label={product ? 'Save changes' : 'Add product'}
            />
        </form>
    );
}

export function ProductImage({ product }: { product: CatalogProduct }) {
    const [failedUrl, setFailedUrl] = useState<string | null>(null);
    return product.image_url && failedUrl !== product.image_url ? (
        <img
            src={product.image_url}
            alt={product.name}
            loading="lazy"
            width={480}
            height={320}
            className="aspect-[3/2] w-full bg-neutral-100 object-contain"
            onError={() => setFailedUrl(product.image_url)}
        />
    ) : (
        <div className="flex aspect-[3/2] w-full items-center justify-center bg-neutral-100 text-sm font-medium text-neutral-400">
            No image available
        </div>
    );
}

export function ProductImageForm({
    product,
    onSaved,
}: {
    product: CatalogProduct;
    onSaved: () => void;
}) {
    const form = useForm<{ image: File | null }>({ image: null });
    const removal = useForm({});
    const submitting = useRef(false);
    const busy = form.processing || removal.processing;
    return (
        <div className="flex flex-col gap-4">
            <div className="overflow-hidden rounded-xl">
                <ProductImage product={product} />
            </div>
            <form
                className="flex flex-col gap-4"
                aria-busy={busy}
                onSubmit={(event) => {
                    event.preventDefault();
                    if (submitting.current) return;
                    submitting.current = true;
                    form.submit(uploadImage(product.id), {
                        preserveScroll: true,
                        forceFormData: true,
                        onSuccess: () => {
                            toast.success('Product image saved');
                            onSaved();
                        },
                        onFinish: () => {
                            submitting.current = false;
                        },
                    });
                }}
            >
                <Field
                    id="product-image"
                    label={product.has_image ? 'Replace image' : 'Upload image'}
                    error={form.errors.image}
                >
                    <input
                        id="product-image"
                        type="file"
                        accept="image/jpeg,image/png,image/webp"
                        required
                        disabled={busy}
                        className="block w-full rounded-lg border border-neutral-300 p-3 text-sm"
                        onChange={(event) =>
                            form.setData(
                                'image',
                                event.target.files?.[0] ?? null,
                            )
                        }
                    />
                </Field>
                <p className="text-xs text-neutral-500">
                    JPEG, PNG or WebP. Up to 8 MB and 6000 × 6000 pixels.
                </p>
                {form.progress && (
                    <p role="status" className="text-sm">
                        Uploading {form.progress.percentage}%
                    </p>
                )}
                <SaveButton
                    processing={busy}
                    label={product.has_image ? 'Replace image' : 'Upload image'}
                />
            </form>
            {product.has_image && (
                <Button
                    variant="outline"
                    disabled={busy}
                    className={`${actionClass} text-red-700 hover:text-red-800`}
                    onClick={() => {
                        if (submitting.current) return;
                        submitting.current = true;
                        removal.submit(removeImage(product.id), {
                            preserveScroll: true,
                            onSuccess: () => {
                                toast.success('Product image removed');
                                onSaved();
                            },
                            onFinish: () => {
                                submitting.current = false;
                            },
                        });
                    }}
                >
                    Remove image
                </Button>
            )}
            <FormErrors errors={removal.errors} />
        </div>
    );
}

export function BranchPriceForm({
    product,
    branch,
}: {
    product: CatalogProduct;
    branch: BranchPrice;
}) {
    const form = useForm<{
        price_override: string | null;
        is_available: boolean;
    }>({
        price_override: branch.price_override,
        is_available: branch.is_available,
    });
    const submitting = useRef(false);
    const inherited = form.data.price_override === null;
    return (
        <form
            className="flex flex-col gap-4 rounded-xl border border-neutral-200 p-4"
            aria-busy={form.processing}
            onSubmit={(event) => {
                event.preventDefault();
                if (submitting.current) return;
                submitting.current = true;
                form.submit(
                    updateBranch({
                        product: product.id,
                        branch: branch.branch_id,
                    }),
                    {
                        preserveScroll: true,
                        onSuccess: () => {
                            toast.success(`${branch.code} override saved`);
                        },
                        onFinish: () => {
                            submitting.current = false;
                        },
                    },
                );
            }}
        >
            <div>
                <h3 className="font-bold">
                    {branch.code}{' '}
                    <span className="font-normal text-neutral-500">
                        · {branch.name}
                    </span>
                </h3>
                <p className="mt-1 text-sm text-neutral-600">
                    Current price:{' '}
                    {branch.price_override === null && 'Default '}
                    {money(branch.effective_price)}
                </p>
            </div>
            <fieldset
                disabled={form.processing}
                className="flex flex-col gap-2"
            >
                <ActiveField
                    label={`Use default price (${money(product.default_price)})`}
                    value={inherited}
                    onChange={(value) =>
                        form.setData(
                            'price_override',
                            value ? null : product.default_price,
                        )
                    }
                />
                {!inherited && (
                    <TextField
                        id={`branch-price-${branch.branch_id}`}
                        label="Branch price (₱)"
                        value={form.data.price_override ?? ''}
                        onChange={(value) =>
                            form.setData('price_override', value)
                        }
                        error={form.errors.price_override}
                    />
                )}
                <ActiveField
                    label="Available at this branch"
                    value={form.data.is_available}
                    onChange={(value) => form.setData('is_available', value)}
                />
            </fieldset>
            {(!product.is_active || !product.category_active) && (
                <p className="text-sm text-amber-800">
                    This product stays unavailable while the product or category
                    is inactive.
                </p>
            )}
            <FormErrors errors={form.errors} />
            <SaveButton
                processing={form.processing}
                label={`Save ${branch.code}`}
            />
        </form>
    );
}
