import { useForm } from '@inertiajs/react';
import { Check, ImageIcon, Plus, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    ActiveField,
    controlClass,
    Field,
    FormErrors,
    money,
    TextField,
} from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import { store, update } from '@/routes/products';
import { destroy as removeImage } from '@/routes/products/image';
import type {
    BranchConfiguration,
    CatalogChoice,
    CatalogProduct,
    ModifierGroup,
} from '@/types/catalog';

type BranchConfig = {
    branch_id: string;
    price_override: string | null;
    is_available: boolean;
    tracks_inventory: boolean;
    low_stock_threshold: number | null;
};

type InlineOption = {
    client_key: string;
    name: string;
    price_delta: string;
    sort_order: number;
    is_active: boolean;
};

type InlineGroup = {
    client_key: string;
    name: string;
    semantic_role: 'size' | null;
    selection_type: 'single' | 'multiple';
    min_select: number;
    max_select: number;
    is_active: boolean;
    options: InlineOption[];
};

const newOption = (sortOrder = 0): InlineOption => ({
    client_key: crypto.randomUUID(),
    name: '',
    price_delta: '0.00',
    sort_order: sortOrder,
    is_active: true,
});

const newGroup = (): InlineGroup => ({
    client_key: crypto.randomUUID(),
    name: '',
    semantic_role: null,
    selection_type: 'single',
    min_select: 0,
    max_select: 1,
    is_active: true,
    options: [newOption()],
});

export function ProductEditorForm({
    product,
    categories,
    groups,
    branches,
    onSaved,
    onCancel,
}: {
    product: CatalogProduct | null;
    categories: CatalogChoice[];
    groups: ModifierGroup[];
    branches: BranchConfiguration[];
    onSaved: () => void;
    onCancel: () => void;
}) {
    const form = useForm<{
        name: string;
        description: string;
        category_id: string;
        default_price: string;
        is_active: boolean;
        modifier_group_ids: string[];
        inline_groups: InlineGroup[];
        branch_configs: BranchConfig[];
        image: File | null;
    }>({
        name: product?.name ?? '',
        description: product?.description ?? '',
        category_id: product?.category_id ?? '',
        default_price: product?.default_price ?? '',
        is_active: product?.is_active ?? true,
        modifier_group_ids: product?.modifier_group_ids ?? [],
        inline_groups: [],
        branch_configs: branches.map((branch) => {
            const existing = product?.branch_prices.find(
                (price) => price.branch_id === branch.branch_id,
            );

            return {
                branch_id: branch.branch_id,
                price_override: existing?.price_override ?? null,
                is_available: existing?.is_available ?? true,
                tracks_inventory: existing?.tracks_inventory ?? false,
                low_stock_threshold: existing?.low_stock_threshold ?? null,
            };
        }),
        image: null,
    });
    const removal = useForm({});
    const submitting = useRef(false);
    const [groupToAttach, setGroupToAttach] = useState('');
    const [imageRemoved, setImageRemoved] = useState(false);
    const attachedGroups = groups.filter((group) =>
        form.data.modifier_group_ids.includes(group.id),
    );
    const unattachedGroups = groups.filter(
        (group) => !form.data.modifier_group_ids.includes(group.id),
    );
    const singleBranch = branches.length === 1;
    const singleBranchConfig = singleBranch
        ? form.data.branch_configs[0]
        : null;
    const busy = form.processing || removal.processing;

    const updateBranchConfig = (index: number, value: BranchConfig) => {
        form.setData(
            'branch_configs',
            form.data.branch_configs.map((item, itemIndex) =>
                itemIndex === index ? value : item,
            ),
        );
    };

    return (
        <form
            className="flex min-h-0 flex-1 flex-col"
            aria-busy={busy}
            onSubmit={(event) => {
                event.preventDefault();
                if (submitting.current) return;
                submitting.current = true;
                form.submit(product ? update(product.id) : store(), {
                    preserveScroll: true,
                    forceFormData: true,
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
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
                <fieldset disabled={busy} className="min-w-0">
                    <div className="space-y-4">
                    <ImagePanel
                        product={product}
                        imageRemoved={imageRemoved}
                        selectedImage={form.data.image}
                        onSelect={(image) => form.setData('image', image)}
                        onRemove={() => {
                            if (!product) return;
                            removal.submit(removeImage(product.id), {
                                preserveScroll: true,
                                onSuccess: () => {
                                    setImageRemoved(true);
                                    toast.success('Product image removed');
                                },
                            });
                        }}
                    />
                    {form.errors.image && (
                        <p role="alert" className="text-xs text-red-700">
                            {form.errors.image}
                        </p>
                    )}

                    <div className="grid gap-4 sm:grid-cols-2">
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
                                onChange={(event) =>
                                    form.setData(
                                        'category_id',
                                        event.target.value,
                                    )
                                }
                            >
                                <option value="">Select category</option>
                                {categories.map((category) => (
                                    <option
                                        key={category.id}
                                        value={category.id}
                                    >
                                        {category.name}
                                        {!category.is_active
                                            ? ' (inactive)'
                                            : ''}
                                    </option>
                                ))}
                            </select>
                        </Field>
                        <TextField
                            id="product-price"
                            label="Price"
                            value={form.data.default_price}
                            onChange={(value) =>
                                form.setData('default_price', value)
                            }
                            error={form.errors.default_price}
                        />
                        <Field id="product-stock" label="Stock on hand">
                            <input
                                id="product-stock"
                                readOnly
                                className={`${controlClass} bg-neutral-50 text-neutral-600`}
                                value={
                                    !singleBranch
                                        ? 'Choose a global branch'
                                        : product?.inventory?.on_hand ??
                                          'Set through Adjust Stock'
                                }
                            />
                        </Field>
                        <Field
                            id="product-threshold"
                            label="Low-stock threshold"
                            error={
                                form.errors[
                                    'branch_configs.0.low_stock_threshold'
                                ]
                            }
                        >
                            <input
                                id="product-threshold"
                                type="number"
                                min={0}
                                step={1}
                                disabled={!singleBranchConfig?.tracks_inventory}
                                className={controlClass}
                                value={
                                    singleBranchConfig?.low_stock_threshold ??
                                    ''
                                }
                                placeholder={
                                    singleBranch
                                        ? 'Enable inventory tracking below'
                                        : 'Configured per branch below'
                                }
                                onChange={(event) => {
                                    if (!singleBranchConfig) return;
                                    updateBranchConfig(0, {
                                        ...singleBranchConfig,
                                        low_stock_threshold:
                                            event.target.value === ''
                                                ? null
                                                : Number(event.target.value),
                                    });
                                }}
                            />
                        </Field>
                        <AvailabilitySwitch
                            active={form.data.is_active}
                            onChange={(isActive) =>
                                form.setData('is_active', isActive)
                            }
                        />
                    </div>

                    <Field
                        id="product-description"
                        label="Description (optional)"
                        error={form.errors.description}
                    >
                        <textarea
                            id="product-description"
                            rows={2}
                            maxLength={5000}
                            className={`${controlClass} h-auto py-3`}
                            value={form.data.description}
                            onChange={(event) =>
                                form.setData(
                                    'description',
                                    event.target.value,
                                )
                            }
                        />
                    </Field>

                    <section className="space-y-3 border-t border-neutral-200 pt-4">
                        <div>
                            <h3 className="text-sm font-bold">
                                Branch configuration
                            </h3>
                            <p className="text-[11.5px] text-neutral-500">
                                {singleBranch
                                    ? `Showing ${branches[0]?.code}, the selected global branch.`
                                    : 'All authorized branches are shown in All Branches scope.'}
                            </p>
                        </div>
                        <div className="grid gap-3 md:grid-cols-2">
                            {branches.map((branch, index) => (
                                <BranchEditor
                                    key={branch.branch_id}
                                    branch={branch}
                                    value={form.data.branch_configs[index]}
                                    hideThreshold={singleBranch}
                                    onChange={(value) =>
                                        updateBranchConfig(index, value)
                                    }
                                />
                            ))}
                        </div>
                    </section>

                    <section className="space-y-3 border-t border-neutral-200 pt-4">
                        <div className="flex items-start justify-between gap-3">
                            <div>
                                <h3 className="text-sm font-bold">Options</h3>
                                <p className="text-[11.5px] text-neutral-500">
                                    What the cashier and QR menu ask when this
                                    product is ordered.
                                </p>
                            </div>
                            <Button
                                type="button"
                                variant="outline"
                                className={actionClass}
                                onClick={() =>
                                    form.setData('inline_groups', [
                                        ...form.data.inline_groups,
                                        newGroup(),
                                    ])
                                }
                            >
                                <Plus className="size-4" /> Add group
                            </Button>
                        </div>

                        {unattachedGroups.length > 0 && (
                            <div className="flex gap-2">
                                <select
                                    aria-label="Reusable Group"
                                    className={controlClass}
                                    value={groupToAttach}
                                    onChange={(event) =>
                                        setGroupToAttach(event.target.value)
                                    }
                                >
                                    <option value="">
                                        Attach an existing Group…
                                    </option>
                                    {unattachedGroups.map((group) => (
                                        <option key={group.id} value={group.id}>
                                            {group.name}
                                            {!group.is_active
                                                ? ' (inactive)'
                                                : ''}
                                        </option>
                                    ))}
                                </select>
                                <Button
                                    type="button"
                                    variant="outline"
                                    className={actionClass}
                                    disabled={!groupToAttach}
                                    onClick={() => {
                                        form.setData('modifier_group_ids', [
                                            ...form.data.modifier_group_ids,
                                            groupToAttach,
                                        ]);
                                        setGroupToAttach('');
                                    }}
                                >
                                    <Check className="size-4" /> Attach
                                </Button>
                            </div>
                        )}

                        {attachedGroups.map((group) => (
                            <AttachedGroupCard
                                key={group.id}
                                group={group}
                                onRemove={() =>
                                    form.setData(
                                        'modifier_group_ids',
                                        form.data.modifier_group_ids.filter(
                                            (id) => id !== group.id,
                                        ),
                                    )
                                }
                            />
                        ))}
                        {form.data.inline_groups.map((group, groupIndex) => (
                            <InlineGroupEditor
                                key={group.client_key}
                                group={group}
                                errors={form.errors}
                                errorPrefix={`inline_groups.${groupIndex}`}
                                onChange={(value) =>
                                    form.setData(
                                        'inline_groups',
                                        form.data.inline_groups.map(
                                            (item, index) =>
                                                index === groupIndex
                                                    ? value
                                                    : item,
                                        ),
                                    )
                                }
                                onRemove={() =>
                                    form.setData(
                                        'inline_groups',
                                        form.data.inline_groups.filter(
                                            (_, index) => index !== groupIndex,
                                        ),
                                    )
                                }
                            />
                        ))}
                        {attachedGroups.length === 0 &&
                            form.data.inline_groups.length === 0 && (
                                <p className="rounded-xl border border-dashed border-neutral-300 px-3 py-5 text-center text-sm text-neutral-500">
                                    No Groups attached. Add a new Group or
                                    attach one from the library.
                                </p>
                            )}
                    </section>
                    </div>
                </fieldset>
            </div>

            <div className="border-t border-neutral-200 bg-white p-4">
                <FormErrors errors={{ ...form.errors, ...removal.errors }} />
                <div className="mt-2 grid grid-cols-[auto_minmax(0,1fr)] gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} px-5`}
                        onClick={onCancel}
                    >
                        Cancel
                    </Button>
                    <Button
                        type="submit"
                        disabled={busy}
                        className="min-h-12 rounded-xl bg-neutral-950 font-bold text-white hover:bg-neutral-800"
                    >
                        <Check className="size-4" />
                        {busy
                            ? 'Saving…'
                            : product
                              ? 'Save product'
                              : 'Add product'}
                    </Button>
                </div>
            </div>
        </form>
    );
}

function ImagePanel({
    product,
    imageRemoved,
    selectedImage,
    onSelect,
    onRemove,
}: {
    product: CatalogProduct | null;
    imageRemoved: boolean;
    selectedImage: File | null;
    onSelect: (image: File | null) => void;
    onRemove: () => void;
}) {
    return (
        <section className="flex items-center gap-3 rounded-xl border border-neutral-200 bg-neutral-50 p-3">
            <div className="flex size-[82px] shrink-0 items-center justify-center overflow-hidden rounded-xl bg-neutral-200 text-neutral-500 [&>img]:aspect-square [&>img]:h-full [&>img]:object-cover">
                {product?.has_image && !imageRemoved ? (
                    <ProductImage product={product} />
                ) : (
                    <ImageIcon className="size-7" />
                )}
            </div>
            <div className="min-w-0 flex-1">
                <p className="text-[13px] font-semibold">Product image</p>
                <p className="mt-1 text-[11.5px] text-neutral-500">
                    Square image, shown in the POS grid and QR menu.
                </p>
                {selectedImage && (
                    <p className="mt-1 truncate text-[11px] font-medium">
                        {selectedImage.name}
                    </p>
                )}
            </div>
            <label className={`${actionClass} cursor-pointer px-3`}>
                <ImageIcon className="size-4" />
                {product?.has_image ? 'Replace' : 'Choose'}
                <input
                    type="file"
                    accept="image/jpeg,image/png,image/webp"
                    className="sr-only"
                    onChange={(event) =>
                        onSelect(event.target.files?.[0] ?? null)
                    }
                />
            </label>
            {product?.has_image && !imageRemoved && (
                <button
                    type="button"
                    aria-label="Remove product image"
                    className="flex size-11 shrink-0 items-center justify-center rounded-xl border border-neutral-200 bg-white text-red-600"
                    onClick={onRemove}
                >
                    <Trash2 className="size-4" />
                </button>
            )}
        </section>
    );
}

function ProductImage({
    product,
}: {
    product: Pick<CatalogProduct, 'name' | 'image_url'>;
}) {
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
        <div className="flex aspect-[3/2] w-full items-center justify-center bg-neutral-100 px-2 text-center text-sm font-medium text-neutral-600">
            No image available
        </div>
    );
}

function AvailabilitySwitch({
    active,
    onChange,
}: {
    active: boolean;
    onChange: (active: boolean) => void;
}) {
    return (
        <div className="space-y-2">
            <p className="text-[11px] font-semibold tracking-[0.06em] text-neutral-500 uppercase">
                Availability
            </p>
            <button
                type="button"
                role="switch"
                aria-checked={active}
                className="flex min-h-12 w-full items-center gap-3 rounded-xl border border-neutral-900 px-3 text-left text-[12.5px] font-semibold"
                onClick={() => onChange(!active)}
            >
                <span
                    className={`flex h-7 w-12 shrink-0 items-center rounded-full p-1 transition ${active ? 'justify-end bg-neutral-950' : 'justify-start bg-neutral-300'}`}
                >
                    <span className="size-5 rounded-full bg-white" />
                </span>
                {active
                    ? 'Active — available in POS and QR menu'
                    : 'Inactive — hidden from ordering'}
            </button>
        </div>
    );
}

function BranchEditor({
    branch,
    value,
    hideThreshold,
    onChange,
}: {
    branch: BranchConfiguration;
    value: BranchConfig;
    hideThreshold: boolean;
    onChange: (value: BranchConfig) => void;
}) {
    return (
        <div className="space-y-2 rounded-xl border border-neutral-200 p-3">
            <p className="text-[13px] font-semibold">
                {branch.code} · {branch.name}
            </p>
            <input
                aria-label={`${branch.code} price override`}
                value={value.price_override ?? ''}
                placeholder="Default price"
                className={controlClass}
                onChange={(event) =>
                    onChange({
                        ...value,
                        price_override: event.target.value || null,
                    })
                }
            />
            <ActiveField
                label="Available at this branch"
                value={value.is_available}
                onChange={(isAvailable) =>
                    onChange({ ...value, is_available: isAvailable })
                }
            />
            <ActiveField
                label="Track inventory"
                value={value.tracks_inventory}
                onChange={(tracksInventory) =>
                    onChange({
                        ...value,
                        tracks_inventory: tracksInventory,
                        low_stock_threshold: tracksInventory
                            ? value.low_stock_threshold
                            : null,
                    })
                }
            />
            {!hideThreshold && value.tracks_inventory && (
                <input
                    aria-label={`${branch.code} low stock threshold`}
                    type="number"
                    min={0}
                    step={1}
                    value={value.low_stock_threshold ?? ''}
                    placeholder="Low-stock threshold"
                    className={controlClass}
                    onChange={(event) =>
                        onChange({
                            ...value,
                            low_stock_threshold:
                                event.target.value === ''
                                    ? null
                                    : Number(event.target.value),
                        })
                    }
                />
            )}
        </div>
    );
}

function AttachedGroupCard({
    group,
    onRemove,
}: {
    group: ModifierGroup;
    onRemove: () => void;
}) {
    return (
        <section className="space-y-2 rounded-xl border border-neutral-200 p-3">
            <div className="grid grid-cols-[minmax(0,1fr)_auto_44px] gap-2">
                <div className={`${controlClass} flex items-center font-semibold`}>
                    {group.name}
                    {!group.is_active && (
                        <span className="ml-2 text-xs text-neutral-500">
                            Inactive
                        </span>
                    )}
                </div>
                <div className={`${controlClass} flex w-28 items-center text-xs font-semibold`}>
                    {group.selection_type === 'single'
                        ? 'One choice'
                        : 'Multiple'}
                </div>
                <button
                    type="button"
                    aria-label={`Detach ${group.name}`}
                    title="Remove from this product"
                    className="flex size-11 items-center justify-center rounded-xl border border-neutral-200 bg-white text-red-600"
                    onClick={onRemove}
                >
                    <Trash2 className="size-4" />
                </button>
            </div>
            {group.options.map((option) => (
                <div
                    key={option.id}
                    className="grid grid-cols-[minmax(0,1fr)_96px_52px] gap-2"
                >
                    <div className={`${controlClass} flex items-center text-sm`}>
                        {option.name}
                    </div>
                    <div className={`${controlClass} flex items-center text-sm tabular-nums`}>
                        {money(option.price_delta)}
                    </div>
                    <div
                        className={`flex min-h-11 items-center justify-center rounded-xl text-xs font-bold ${option.is_active ? 'bg-neutral-950 text-white' : 'border border-neutral-200 text-neutral-500'}`}
                    >
                        {option.is_active ? 'On' : 'Off'}
                    </div>
                </div>
            ))}
            <p className="text-[11px] text-neutral-500">
                Edit reusable Group details from the Groups tab.
            </p>
        </section>
    );
}

function InlineGroupEditor({
    group,
    errorPrefix,
    errors,
    onChange,
    onRemove,
}: {
    group: InlineGroup;
    errorPrefix: string;
    errors: Record<string, string>;
    onChange: (group: InlineGroup) => void;
    onRemove: () => void;
}) {
    const updateOption = (index: number, option: InlineOption) =>
        onChange({
            ...group,
            options: group.options.map((item, itemIndex) =>
                itemIndex === index ? option : item,
            ),
        });

    return (
        <section className="space-y-3 rounded-xl border border-neutral-200 bg-neutral-50/60 p-3">
            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px_44px]">
                <input
                    aria-label="Group title"
                    value={group.name}
                    placeholder="Group title"
                    className={controlClass}
                    onChange={(event) =>
                        onChange({ ...group, name: event.target.value })
                    }
                />
                <select
                    aria-label="Group selection type"
                    value={group.selection_type}
                    className={controlClass}
                    onChange={(event) => {
                        const selectionType = event.target.value as
                            | 'single'
                            | 'multiple';
                        onChange({
                            ...group,
                            selection_type: selectionType,
                            max_select:
                                selectionType === 'single'
                                    ? 1
                                    : Math.max(group.max_select, 1),
                            min_select:
                                selectionType === 'single'
                                    ? Math.min(group.min_select, 1)
                                    : group.min_select,
                        });
                    }}
                >
                    <option value="single">One choice</option>
                    <option value="multiple">Multiple choices</option>
                </select>
                <DeleteButton label="Remove unsaved Group" onClick={onRemove} />
            </div>
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-4">
                <NumberSetting
                    label="Minimum"
                    value={group.min_select}
                    onChange={(value) =>
                        onChange({ ...group, min_select: value })
                    }
                />
                <NumberSetting
                    label="Maximum"
                    value={group.max_select}
                    disabled={group.selection_type === 'single'}
                    onChange={(value) =>
                        onChange({ ...group, max_select: value })
                    }
                />
                <ToggleSetting
                    label="Size prefix"
                    checked={group.semantic_role === 'size'}
                    onChange={(checked) =>
                        onChange({
                            ...group,
                            semantic_role: checked ? 'size' : null,
                        })
                    }
                />
                <ToggleSetting
                    label="Active"
                    checked={group.is_active}
                    onChange={(isActive) =>
                        onChange({ ...group, is_active: isActive })
                    }
                />
            </div>
            {errors[`${errorPrefix}.name`] && (
                <p className="text-xs text-red-700">
                    {errors[`${errorPrefix}.name`]}
                </p>
            )}
            <div className="space-y-2">
                {group.options.map((option, optionIndex) => (
                    <div
                        key={option.client_key}
                        className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_110px_64px_44px]"
                    >
                        <input
                            aria-label={`Option ${optionIndex + 1} name`}
                            value={option.name}
                            placeholder="Option name"
                            className={controlClass}
                            onChange={(event) =>
                                updateOption(optionIndex, {
                                    ...option,
                                    name: event.target.value,
                                })
                            }
                        />
                        <div className="relative">
                            <span className="absolute top-1/2 left-3 -translate-y-1/2 text-xs text-neutral-500">
                                ₱
                            </span>
                            <input
                                aria-label={`Option ${optionIndex + 1} price adjustment`}
                                value={option.price_delta}
                                className={`${controlClass} pl-7`}
                                onChange={(event) =>
                                    updateOption(optionIndex, {
                                        ...option,
                                        price_delta: event.target.value,
                                    })
                                }
                            />
                        </div>
                        <button
                            type="button"
                            className={`min-h-11 rounded-xl border text-xs font-bold ${option.is_active ? 'border-neutral-950 bg-neutral-950 text-white' : 'border-neutral-300 bg-white text-neutral-500'}`}
                            onClick={() =>
                                updateOption(optionIndex, {
                                    ...option,
                                    is_active: !option.is_active,
                                })
                            }
                        >
                            {option.is_active ? 'On' : 'Off'}
                        </button>
                        <DeleteButton
                            label={`Remove option ${optionIndex + 1}`}
                            disabled={group.options.length === 1}
                            onClick={() =>
                                onChange({
                                    ...group,
                                    options: group.options.filter(
                                        (_, index) => index !== optionIndex,
                                    ),
                                })
                            }
                        />
                    </div>
                ))}
                <Button
                    type="button"
                    variant="outline"
                    className={`${actionClass} w-full border-dashed`}
                    onClick={() =>
                        onChange({
                            ...group,
                            options: [
                                ...group.options,
                                newOption(group.options.length),
                            ],
                        })
                    }
                >
                    <Plus className="size-4" /> Add option
                </Button>
            </div>
        </section>
    );
}

function NumberSetting({
    label,
    value,
    disabled = false,
    onChange,
}: {
    label: string;
    value: number;
    disabled?: boolean;
    onChange: (value: number) => void;
}) {
    return (
        <label className="space-y-1 text-[11px] font-semibold">
            <span>{label}</span>
            <input
                type="number"
                min={0}
                disabled={disabled}
                value={value}
                className={controlClass}
                onChange={(event) => onChange(Number(event.target.value))}
            />
        </label>
    );
}

function ToggleSetting({
    label,
    checked,
    onChange,
}: {
    label: string;
    checked: boolean;
    onChange: (checked: boolean) => void;
}) {
    return (
        <label className="flex min-h-11 items-center gap-2 rounded-xl border border-neutral-200 bg-white px-3 text-[11px] font-semibold">
            <input
                type="checkbox"
                checked={checked}
                className="size-4 accent-neutral-950"
                onChange={(event) => onChange(event.target.checked)}
            />
            {label}
        </label>
    );
}

function DeleteButton({
    label,
    disabled = false,
    onClick,
}: {
    label: string;
    disabled?: boolean;
    onClick: () => void;
}) {
    return (
        <button
            type="button"
            aria-label={label}
            title={label}
            disabled={disabled}
            className="flex size-11 items-center justify-center rounded-xl border border-red-200 bg-white text-red-700 disabled:cursor-not-allowed disabled:opacity-35"
            onClick={onClick}
        >
            <Trash2 className="size-4" />
        </button>
    );
}
