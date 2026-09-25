import { useForm } from '@inertiajs/react';
import { Check, ImageIcon, Layers3, Plus, Trash2 } from 'lucide-react';
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
import {
    MODIFIER_ROLE_OPTIONS,
    activeSizeGroupNames,
    modifierRoleFromValue,
    modifierRoleHelp,
    modifierRoleLabel,
} from '@/lib/modifier-roles';
import { store, update } from '@/routes/products';
import { update as updateBranchProduct } from '@/routes/products/branches';
import { destroy as removeImage } from '@/routes/products/image';
import type {
    BranchConfiguration,
    CatalogChoice,
    CatalogProduct,
    ModifierGroup,
} from '@/types/catalog';

type BranchConfig = {
    branch_id: string;
    /** Client-only: whether this Branch sells the Product. Only selected Branches are submitted (explicit assortment). */
    in_assortment: boolean;
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
    semantic_role: 'size' | 'instruction' | null;
    selection_type: 'single' | 'multiple';
    min_select: number;
    max_select: number;
    is_active: boolean;
    options: InlineOption[];
};

/** Errors shown beside their field on the Product tab; the summary box lists only the rest, so none appears twice. */
export const PRODUCT_INLINE_ERRORS: readonly (string | RegExp)[] = [
    'image',
    'name',
    'category_id',
    'default_price',
    'description',
    'modifier_group_ids',
    /^inline_groups\.\d+\.name$/,
];

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
    initialSection = 'product',
    branchOnly = false,
}: {
    product: CatalogProduct | null;
    categories: CatalogChoice[];
    groups: ModifierGroup[];
    branches: BranchConfiguration[];
    onSaved: () => void;
    onCancel: () => void;
    initialSection?: 'product' | 'branch';
    /**
     * Branch-scoped Product management: only this Branch's configuration (sold here, price, tracking, threshold) is
     * editable, saved through the Branch configuration endpoint. The shared definition is never submitted.
     */
    branchOnly?: boolean;
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
                /** A new Product created while one Branch is selected is added to it; otherwise nothing is implicit. */
                in_assortment:
                    existing?.in_assortment ??
                    (product === null && branches.length === 1),
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
    const [activeSection, setActiveSection] = useState<'product' | 'branch'>(
        branchOnly ? 'branch' : initialSection,
    );
    const [assigningGroups, setAssigningGroups] = useState(false);
    const [groupAssignment, setGroupAssignment] = useState<string[]>([]);
    const [imageRemoved, setImageRemoved] = useState(false);
    const attachedGroups = groups.filter((group) =>
        form.data.modifier_group_ids.includes(group.id),
    );
    const singleBranch = branches.length === 1;
    const busy = form.processing || removal.processing;
    const removalErrors: Record<string, string> = removal.errors;
    const imageError = form.errors.image ?? removalErrors.image;

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
                const branchConfig = form.data.branch_configs[0];
                if (branchOnly) {
                    if (!product || !branchConfig) return;
                    submitting.current = true;
                    form.transform((data) => ({ ...data.branch_configs[0] }));
                    form.submit(
                        updateBranchProduct({
                            product: product.id,
                            branch: branchConfig.branch_id,
                        }),
                        {
                            preserveScroll: true,
                            onSuccess: () => {
                                toast.success('Branch settings saved');
                                onSaved();
                            },
                            onFinish: () => {
                                submitting.current = false;
                            },
                        },
                    );
                    return;
                }
                submitting.current = true;
                /** A Branch joins the assortment only when explicitly selected; unselected Branches are never sent. */
                form.transform((data) => ({
                    ...data,
                    branch_configs: data.branch_configs
                        .filter((config) => config.in_assortment)
                        .map(({ in_assortment: _member, ...config }) => config),
                }));
                form.submit(product ? update(product.id) : store(), {
                    preserveScroll: true,
                    forceFormData: true,
                    onSuccess: () => {
                        toast.success('Product saved');
                        onSaved();
                    },
                    onError: (errors) => {
                        /** Show the tab that owns the error, so an inline field error is never hidden. */
                        setActiveSection(
                            Object.keys(errors).every((key) =>
                                key.startsWith('branch_configs.'),
                            )
                                ? 'branch'
                                : 'product',
                        );
                    },
                    onFinish: () => {
                        submitting.current = false;
                    },
                });
            }}
        >
            <div
                role="tablist"
                aria-label="Product editor sections"
                hidden={branchOnly}
                className="grid shrink-0 grid-cols-2 gap-[3px] border-b border-neutral-200 bg-white px-4 py-3 sm:px-5"
            >
                {(
                    [
                        ['product', 'Product'],
                        ['branch', 'Branch configuration'],
                    ] as const
                ).map(([section, label]) => (
                    <button
                        key={section}
                        type="button"
                        role="tab"
                        aria-selected={activeSection === section}
                        className={`min-h-11 rounded-[10px] px-3 text-[13px] font-semibold transition ${activeSection === section ? 'bg-neutral-950 text-white' : 'bg-neutral-100 text-neutral-600 hover:bg-neutral-200'}`}
                        onClick={() => setActiveSection(section)}
                    >
                        {label}
                    </button>
                ))}
            </div>
            <div className="min-h-0 flex-1 overflow-y-auto px-4 py-4 sm:px-5">
                <fieldset disabled={busy} className="min-w-0">
                    <div className="space-y-4">
                        {activeSection === 'product' && (
                            <>
                                <ImagePanel
                                    product={product}
                                    imageRemoved={imageRemoved}
                                    selectedImage={form.data.image}
                                    onSelect={(image) =>
                                        form.setData('image', image)
                                    }
                                    onRemove={() => {
                                        if (!product) return;
                                        removal.submit(
                                            removeImage(product.id),
                                            {
                                                preserveScroll: true,
                                                onSuccess: () => {
                                                    setImageRemoved(true);
                                                    toast.success(
                                                        'Product image removed',
                                                    );
                                                },
                                            },
                                        );
                                    }}
                                />
                                {imageError && (
                                    <p
                                        role="alert"
                                        className="text-xs text-red-700"
                                    >
                                        {imageError}
                                    </p>
                                )}

                                <div className="grid gap-4 sm:grid-cols-2">
                                    <TextField
                                        id="product-name"
                                        label="Product name"
                                        value={form.data.name}
                                        onChange={(value) =>
                                            form.setData('name', value)
                                        }
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
                                            <option value="">
                                                Select category
                                            </option>
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
                            </>
                        )}

                        {activeSection === 'branch' && (
                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-bold">
                                        Branch configuration
                                    </h3>
                                    <p className="text-[11.5px] text-neutral-500">
                                        {branchOnly
                                            ? `Settings for ${branches[0]?.code} only. The product name, image, category and options are shared by every Branch and managed business-wide.`
                                            : singleBranch
                                              ? `Showing ${branches[0]?.code}, the selected global branch.`
                                              : 'Choose the Branches that sell this product. A product sold nowhere stays in the shared catalog until you add it to a Branch.'}
                                    </p>
                                </div>
                                <div className="grid gap-3 md:grid-cols-2">
                                    {branches.map((branch, index) => (
                                        <BranchEditor
                                            key={branch.branch_id}
                                            branch={branch}
                                            member={
                                                product?.branch_prices.find(
                                                    (price) =>
                                                        price.branch_id ===
                                                        branch.branch_id,
                                                )?.in_assortment ?? false
                                            }
                                            value={
                                                form.data.branch_configs[index]
                                            }
                                            showOnHand={singleBranch}
                                            currentOnHand={
                                                product?.inventory?.on_hand ??
                                                null
                                            }
                                            onChange={(value) =>
                                                updateBranchConfig(index, value)
                                            }
                                        />
                                    ))}
                                </div>
                            </section>
                        )}

                        {activeSection === 'product' && (
                            <section className="space-y-3 border-t border-neutral-200 pt-4">
                                <div className="flex flex-col items-start gap-3 sm:flex-row sm:justify-between">
                                    <div>
                                        <h3 className="text-sm font-bold">
                                            Options
                                        </h3>
                                        <p className="text-[11.5px] text-neutral-500">
                                            What the cashier and QR menu ask
                                            when this product is ordered.
                                        </p>
                                    </div>
                                    <div className="grid w-full grid-cols-2 gap-2 sm:flex sm:w-auto sm:shrink-0 sm:flex-wrap sm:justify-end">
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className={`${actionClass} min-w-0 px-2`}
                                            disabled={groups.length === 0}
                                            onClick={() => {
                                                setGroupAssignment([
                                                    ...form.data
                                                        .modifier_group_ids,
                                                ]);
                                                setAssigningGroups(true);
                                            }}
                                        >
                                            <Layers3 className="size-4" />
                                            Assign Group
                                        </Button>
                                        <Button
                                            type="button"
                                            variant="outline"
                                            className={`${actionClass} min-w-0 px-2`}
                                            onClick={() =>
                                                form.setData('inline_groups', [
                                                    ...form.data.inline_groups,
                                                    newGroup(),
                                                ])
                                            }
                                        >
                                            <Plus className="size-4" /> Add
                                            group
                                        </Button>
                                    </div>
                                </div>

                                {assigningGroups && (
                                    <AssignGroupPicker
                                        groups={groups}
                                        selectedIds={groupAssignment}
                                        onChange={setGroupAssignment}
                                        onCancel={() =>
                                            setAssigningGroups(false)
                                        }
                                        onAssign={() => {
                                            form.setData('modifier_group_ids', [
                                                ...new Set(groupAssignment),
                                            ]);
                                            setAssigningGroups(false);
                                        }}
                                    />
                                )}

                                {form.errors.modifier_group_ids && (
                                    <p
                                        role="alert"
                                        className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-800"
                                    >
                                        {form.errors.modifier_group_ids}
                                    </p>
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
                                {form.data.inline_groups.map(
                                    (group, groupIndex) => (
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
                                                        (_, index) =>
                                                            index !==
                                                            groupIndex,
                                                    ),
                                                )
                                            }
                                        />
                                    ),
                                )}
                                {attachedGroups.length === 0 &&
                                    form.data.inline_groups.length === 0 && (
                                        <p className="rounded-xl border border-dashed border-neutral-300 px-3 py-5 text-center text-sm text-neutral-500">
                                            No Groups attached. Groups are
                                            optional: save as is, or add a new
                                            Group or attach one from the
                                            library.
                                        </p>
                                    )}
                            </section>
                        )}
                    </div>
                </fieldset>
            </div>

            <div className="border-t border-neutral-200 bg-white p-4">
                <FormErrors
                    errors={{ ...form.errors, ...removalErrors }}
                    inline={PRODUCT_INLINE_ERRORS}
                />
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
                            : branchOnly
                              ? 'Save Branch settings'
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
    member,
    value,
    showOnHand,
    currentOnHand,
    onChange,
}: {
    branch: BranchConfiguration;
    /** Already in this Branch's assortment (removal is its own action on the Products page). */
    member: boolean;
    value: BranchConfig;
    showOnHand: boolean;
    currentOnHand: number | null;
    onChange: (value: BranchConfig) => void;
}) {
    if (!value.in_assortment) {
        return (
            <div className="space-y-2 rounded-xl border border-dashed border-neutral-300 p-3">
                <p className="text-[13px] font-semibold">
                    {branch.code} · {branch.name}
                </p>
                <p className="text-[11.5px] leading-5 text-neutral-500">
                    Not in the {branch.code} assortment: it is not sold there.
                </p>
                <ActiveField
                    label={`Sell at ${branch.code}`}
                    value={false}
                    onChange={(sell) =>
                        onChange({ ...value, in_assortment: sell })
                    }
                />
            </div>
        );
    }

    return (
        <div className="space-y-3 rounded-xl border border-neutral-200 p-3">
            <p className="text-[13px] font-semibold">
                {branch.code} · {branch.name}
            </p>
            {member ? (
                <p className="text-[11.5px] text-neutral-500">
                    In the {branch.code} assortment. To stop selling it there,
                    use Remove from {branch.code} on the Products page.
                </p>
            ) : (
                <ActiveField
                    label={`Sell at ${branch.code}`}
                    value
                    onChange={(sell) =>
                        onChange({ ...value, in_assortment: sell })
                    }
                />
            )}
            <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-1 lg:grid-cols-2">
                <Field
                    id={`branch-price-${branch.branch_id}`}
                    label="Price override"
                >
                    <input
                        id={`branch-price-${branch.branch_id}`}
                        value={value.price_override ?? ''}
                        placeholder="Use default price"
                        className={controlClass}
                        onChange={(event) =>
                            onChange({
                                ...value,
                                price_override: event.target.value || null,
                            })
                        }
                    />
                </Field>
                {showOnHand && (
                    <Field
                        id={`branch-stock-${branch.branch_id}`}
                        label="Stock on hand"
                    >
                        <input
                            id={`branch-stock-${branch.branch_id}`}
                            readOnly
                            className={`${controlClass} bg-neutral-50 text-neutral-600`}
                            value={currentOnHand ?? 'Set through Adjust Stock'}
                        />
                    </Field>
                )}
                {value.tracks_inventory && (
                    <Field
                        id={`branch-threshold-${branch.branch_id}`}
                        label="Low-stock threshold"
                    >
                        <input
                            id={`branch-threshold-${branch.branch_id}`}
                            type="number"
                            min={0}
                            step={1}
                            value={value.low_stock_threshold ?? ''}
                            placeholder="Optional"
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
                    </Field>
                )}
            </div>
            <ActiveField
                label="Available now (off = temporarily unavailable)"
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
            {!showOnHand && (
                <p className="text-[11px] leading-5 text-neutral-500">
                    Stock on hand is managed in Inventory. Select this branch
                    globally to view its exact quantity.
                </p>
            )}
        </div>
    );
}

function AssignGroupPicker({
    groups,
    selectedIds,
    onChange,
    onCancel,
    onAssign,
}: {
    groups: ModifierGroup[];
    selectedIds: string[];
    onChange: (ids: string[]) => void;
    onCancel: () => void;
    onAssign: () => void;
}) {
    const sizeConflict = activeSizeGroupNames(groups, selectedIds);

    return (
        <section className="space-y-3 rounded-xl border border-neutral-300 bg-neutral-50 p-3">
            <div>
                <h4 className="text-[13px] font-bold">
                    Assign existing Groups
                </h4>
                <p className="text-[11.5px] text-neutral-500">
                    Select every reusable Group this Product should offer.
                </p>
            </div>
            <div className="grid max-h-56 gap-2 overflow-y-auto sm:grid-cols-2">
                {groups.map((group) => {
                    const selected = selectedIds.includes(group.id);

                    return (
                        <label
                            key={group.id}
                            className={`flex min-h-12 cursor-pointer items-start gap-3 rounded-xl border bg-white p-3 ${selected ? 'border-neutral-950' : 'border-neutral-200'}`}
                        >
                            <input
                                type="checkbox"
                                checked={selected}
                                className="mt-0.5 size-5 shrink-0 accent-neutral-950"
                                onChange={() =>
                                    onChange(
                                        selected
                                            ? selectedIds.filter(
                                                  (id) => id !== group.id,
                                              )
                                            : [...selectedIds, group.id],
                                    )
                                }
                            />
                            <span className="min-w-0">
                                <span className="block text-[12.5px] font-semibold break-words">
                                    {group.name}
                                </span>
                                <span className="block text-[11px] text-neutral-500">
                                    {modifierRoleLabel(group.semantic_role)} ·{' '}
                                    {group.options.length} option
                                    {group.options.length === 1 ? '' : 's'}
                                    {!group.is_active ? ' · Inactive' : ''}
                                </span>
                            </span>
                        </label>
                    );
                })}
            </div>
            {sizeConflict.length > 1 && (
                <p
                    role="alert"
                    className="rounded-xl border border-red-200 bg-red-50 p-3 text-xs leading-5 text-red-800"
                >
                    Choose one Size group. {sizeConflict.join(' and ')} both
                    define base recipe sizes.
                </p>
            )}
            <div className="grid gap-2 sm:grid-cols-2">
                <Button
                    type="button"
                    variant="outline"
                    className={actionClass}
                    onClick={onCancel}
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    className="min-h-11 rounded-xl bg-neutral-950 font-bold text-white hover:bg-neutral-800"
                    disabled={sizeConflict.length > 1}
                    onClick={onAssign}
                >
                    <Check className="size-4" /> Assign selected
                </Button>
            </div>
        </section>
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
                <div
                    className={`${controlClass} flex items-center font-semibold`}
                >
                    {group.name}
                    {!group.is_active && (
                        <span className="ml-2 text-xs text-neutral-500">
                            Inactive
                        </span>
                    )}
                </div>
                <div
                    className={`${controlClass} flex w-36 items-center text-xs font-semibold`}
                    title={modifierRoleHelp(group.semantic_role)}
                >
                    {modifierRoleLabel(group.semantic_role)}
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
                    <div
                        className={`${controlClass} flex items-center text-sm`}
                    >
                        {option.name}
                    </div>
                    <div
                        className={`${controlClass} flex items-center text-sm tabular-nums`}
                    >
                        {group.semantic_role === 'instruction'
                            ? 'Price-neutral'
                            : money(option.price_delta)}
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
            <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_170px_150px_44px]">
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
                    aria-label="Group behavior"
                    value={group.semantic_role ?? ''}
                    className={controlClass}
                    onChange={(event) => {
                        const role = modifierRoleFromValue(event.target.value);
                        onChange({
                            ...group,
                            semantic_role: role,
                            ...(role === 'instruction'
                                ? {
                                      selection_type: 'multiple' as const,
                                      min_select: 0,
                                      max_select: Math.max(3, group.max_select),
                                      options: group.options.map((option) => ({
                                          ...option,
                                          price_delta: '0.00',
                                      })),
                                  }
                                : {}),
                        });
                    }}
                >
                    {MODIFIER_ROLE_OPTIONS.map((option) => (
                        <option key={option.label} value={option.value}>
                            {option.label}
                        </option>
                    ))}
                </select>
                <select
                    aria-label="Group selection type"
                    value={group.selection_type}
                    disabled={group.semantic_role === 'instruction'}
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
            <p className="text-xs leading-5 text-neutral-600">
                <span className="font-semibold text-neutral-950">
                    {modifierRoleLabel(group.semantic_role)}:
                </span>{' '}
                {modifierRoleHelp(group.semantic_role)}
            </p>
            {group.semantic_role === 'instruction' && (
                <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                    Instructions are optional, allow multiple selections, and
                    never change the Product name or price.
                </p>
            )}
            <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                <NumberSetting
                    label="Minimum"
                    value={group.min_select}
                    disabled={group.semantic_role === 'instruction'}
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
                    label="Active"
                    checked={group.is_active}
                    onChange={(isActive) =>
                        onChange({ ...group, is_active: isActive })
                    }
                />
            </div>
            {errors[`${errorPrefix}.name`] && (
                <p role="alert" className="text-xs text-red-700">
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
                        {group.semantic_role === 'instruction' ? (
                            <div
                                className={`${controlClass} flex items-center text-xs font-semibold text-neutral-500`}
                            >
                                ₱0.00 fixed
                            </div>
                        ) : (
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
                        )}
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
