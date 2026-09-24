import { useForm, usePage } from '@inertiajs/react';
import { Check, Plus, SlidersHorizontal, Trash2 } from 'lucide-react';
import { useRef, useState } from 'react';
import { toast } from 'sonner';
import {
    actionClass,
    ActiveField,
    CatalogDialog,
    CatalogPage,
    controlClass,
    Field,
    FormErrors,
    money,
    SaveButton,
    Status,
    TextField,
} from '@/components/catalog-ui';
import { ownerPanelClass } from '@/components/owner-ui';
import { Button } from '@/components/ui/button';
import {
    MODIFIER_ROLE_OPTIONS,
    modifierRoleFromValue,
    modifierRoleHelp,
    modifierRoleLabel,
} from '@/lib/modifier-roles';
import { store, update } from '@/routes/modifier-groups';
import { update as updateGroupProducts } from '@/routes/modifier-groups/products';
import type { CatalogChoice, ModifierGroup } from '@/types/catalog';

export default function Modifiers({
    groups,
    products,
}: {
    groups: ModifierGroup[];
    products: CatalogChoice[];
}) {
    const createRequested = usePage().url.includes('create=group');
    const [editing, setEditing] = useState<ModifierGroup | null | undefined>(
        createRequested ? null : undefined,
    );
    const [assigning, setAssigning] = useState<ModifierGroup | null>(null);
    return (
        <CatalogPage tab="Groups" counts={{ Groups: groups.length }}>
            {groups.length === 0 ? (
                <div className={`${ownerPanelClass} px-5 py-14 text-center`}>
                    <SlidersHorizontal className="mx-auto size-7 text-[#aaa]" />
                    <h2 className="mt-3 text-sm font-semibold">
                        No Groups yet
                    </h2>
                    <p className="mt-1 text-[12.5px] text-[#767676]">
                        Create a group, then add options such as extra rice or
                        egg.
                    </p>
                </div>
            ) : (
                <div className="grid items-start gap-3 lg:grid-cols-2">
                    {groups.map((group) => (
                        <section
                            key={group.id}
                            className={`${ownerPanelClass} flex flex-col gap-3 p-3.5 sm:p-4`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="min-w-0 text-[15px] font-semibold break-words">
                                    {group.name}
                                </h2>
                                <Status active={group.is_active} />
                            </div>
                            <p className="text-[12px] text-[#767676]">
                                {group.semantic_role === 'instruction'
                                    ? 'Instructions · Optional, multiple choices · Price-neutral'
                                    : `${modifierRoleLabel(group.semantic_role)} · ${group.selection_type === 'single' ? 'One choice' : 'Multiple choices'} · Select ${group.min_select}–${group.max_select}`}
                            </p>
                            {group.options.length === 0 ? (
                                <p className="rounded-lg bg-neutral-50 p-4 text-sm text-neutral-500">
                                    No options in this group.
                                </p>
                            ) : (
                                <ul className="divide-y divide-[#eeeeee] rounded-xl border border-[#eeeeee] px-3">
                                    {group.options.map((option) => (
                                        <li
                                            key={option.id}
                                            className="flex flex-wrap items-center gap-2.5 py-2.5"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="text-[13px] font-semibold break-words">
                                                    {option.name}
                                                </p>
                                                <p className="text-[11.5px] text-[#767676]">
                                                    {group.semantic_role ===
                                                    'instruction'
                                                        ? 'Preparation instruction'
                                                        : `+${money(option.price_delta)}`}
                                                </p>
                                            </div>
                                            <Status active={option.is_active} />
                                        </li>
                                    ))}
                                </ul>
                            )}
                            <div className="grid grid-cols-2 gap-2">
                                <Button
                                    variant="outline"
                                    className={`${actionClass} w-full`}
                                    onClick={() => setEditing(group)}
                                >
                                    Edit
                                </Button>
                                <Button
                                    variant="outline"
                                    className={`${actionClass} w-full`}
                                    onClick={() => setAssigning(group)}
                                >
                                    Assign
                                </Button>
                            </div>
                        </section>
                    ))}
                </div>
            )}
            <CatalogDialog
                open={editing !== undefined}
                onClose={() => setEditing(undefined)}
                title={editing ? 'Edit Group' : 'Add Group'}
                description="Set how many options a customer may choose."
            >
                {editing !== undefined && (
                    <GroupForm
                        key={editing?.id ?? 'new'}
                        group={editing}
                        onSaved={() => setEditing(undefined)}
                    />
                )}
            </CatalogDialog>
            <CatalogDialog
                open={assigning !== null}
                onClose={() => setAssigning(null)}
                title={`Assign ${assigning?.name ?? 'Group'}`}
                description="Choose the Products that should use this reusable Group."
            >
                {assigning && (
                    <AssignGroupForm
                        key={assigning.id}
                        group={assigning}
                        products={products}
                        onSaved={() => setAssigning(null)}
                    />
                )}
            </CatalogDialog>
        </CatalogPage>
    );
}

type GroupOptionInput = {
    id: string | null;
    client_key: string;
    name: string;
    price_delta: string;
    sort_order: number;
    is_active: boolean;
};

function GroupForm({
    group,
    onSaved,
}: {
    group: ModifierGroup | null;
    onSaved: () => void;
}) {
    const form = useForm<{
        name: string;
        semantic_role: 'size' | 'instruction' | null;
        selection_type: 'single' | 'multiple';
        min_select: string;
        max_select: string;
        is_active: boolean;
        options: GroupOptionInput[];
    }>({
        name: group?.name ?? '',
        semantic_role: group?.semantic_role ?? null,
        selection_type: group?.selection_type ?? 'single',
        min_select: String(group?.min_select ?? 0),
        max_select: String(group?.max_select ?? 1),
        is_active: group?.is_active ?? true,
        options:
            group?.options.map((option) => ({
                id: option.id,
                client_key: option.id,
                name: option.name,
                price_delta: option.price_delta,
                sort_order: option.sort_order,
                is_active: option.is_active,
            })) ?? [],
    });
    const submitting = useRef(false);
    const updateOption = (
        index: number,
        option: (typeof form.data.options)[number],
    ) => {
        form.setData(
            'options',
            form.data.options.map((item, itemIndex) =>
                itemIndex === index ? option : item,
            ),
        );
    };

    return (
        <form
            className="flex flex-col gap-4"
            aria-busy={form.processing}
            onSubmit={(event) => {
                event.preventDefault();
                if (submitting.current) return;
                submitting.current = true;
                form.transform((data) => ({
                    ...data,
                    options: data.options.map((option, index) => ({
                        ...option,
                        price_delta:
                            data.semantic_role === 'instruction'
                                ? '0.00'
                                : option.price_delta,
                        sort_order: index,
                    })),
                }));
                form.submit(group ? update(group.id) : store(), {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Group saved');
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
                <div className="grid gap-2 sm:grid-cols-[minmax(0,1fr)_150px_150px]">
                    <input
                        id="group-name"
                        aria-label="Group title"
                        placeholder="Group title"
                        className={controlClass}
                        value={form.data.name}
                        onChange={(event) =>
                            form.setData('name', event.target.value)
                        }
                    />
                    <select
                        id="group-semantic-role"
                        aria-label="Group behavior"
                        className={controlClass}
                        value={form.data.semantic_role ?? ''}
                        onChange={(event) => {
                            const role = modifierRoleFromValue(
                                event.target.value,
                            );
                            form.setData((data) => ({
                                ...data,
                                semantic_role: role,
                                options:
                                    role === 'instruction'
                                        ? data.options.map((option) => ({
                                              ...option,
                                              price_delta: '0.00',
                                          }))
                                        : data.options,
                                ...(role === 'instruction'
                                    ? {
                                          selection_type: 'multiple',
                                          min_select: '0',
                                          max_select: String(
                                              Math.max(
                                                  3,
                                                  Number(data.max_select),
                                              ),
                                          ),
                                      }
                                    : {}),
                            }));
                        }}
                    >
                        {MODIFIER_ROLE_OPTIONS.map((option) => (
                            <option key={option.label} value={option.value}>
                                {option.label}
                            </option>
                        ))}
                    </select>
                    <select
                        id="group-selection"
                        aria-label="Group selection type"
                        className={controlClass}
                        value={form.data.selection_type}
                        disabled={form.data.semantic_role === 'instruction'}
                        onChange={(event) => {
                            const type = event.target.value as
                                | 'single'
                                | 'multiple';
                            form.setData((data) => ({
                                ...data,
                                selection_type: type,
                                max_select:
                                    type === 'single' ? '1' : data.max_select,
                                min_select:
                                    type === 'single'
                                        ? String(
                                              Math.min(
                                                  Number(data.min_select),
                                                  1,
                                              ),
                                          )
                                        : data.min_select,
                            }));
                        }}
                    >
                        <option value="single">One choice</option>
                        <option value="multiple">Multiple choices</option>
                    </select>
                </div>
                {form.errors.name && (
                    <p className="text-xs text-red-700">{form.errors.name}</p>
                )}
                <p
                    id="group-semantic-role-help"
                    className="text-xs leading-5 text-[#666]"
                >
                    <span className="font-semibold text-[#111]">
                        {modifierRoleLabel(form.data.semantic_role)}:
                    </span>{' '}
                    {modifierRoleHelp(form.data.semantic_role)}
                </p>
                {form.errors.semantic_role && (
                    <p role="alert" className="text-xs text-red-700">
                        {form.errors.semantic_role}
                    </p>
                )}
                {form.data.semantic_role === 'instruction' && (
                    <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Instructions are optional preparation choices. They
                        allow multiple selections and never change the Product
                        name or price.
                    </p>
                )}
                <div className="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    {form.data.semantic_role === 'instruction' ? (
                        <Field
                            id="group-min"
                            label="Minimum choices"
                            error={form.errors.min_select}
                        >
                            <input
                                id="group-min"
                                disabled
                                value="0"
                                className={controlClass}
                            />
                        </Field>
                    ) : (
                        <TextField
                            id="group-min"
                            label="Minimum choices"
                            type="number"
                            value={form.data.min_select}
                            onChange={(value) =>
                                form.setData('min_select', value)
                            }
                            error={form.errors.min_select}
                        />
                    )}
                    <TextField
                        id="group-max"
                        label="Maximum choices"
                        type="number"
                        value={form.data.max_select}
                        onChange={(value) => form.setData('max_select', value)}
                        error={form.errors.max_select}
                    />
                    <div className="col-span-2 space-y-2 sm:col-span-1">
                        <span className="text-[11px] font-semibold tracking-[0.06em] text-[#777] uppercase">
                            Status
                        </span>
                        <ActiveField
                            value={form.data.is_active}
                            onChange={(value) =>
                                form.setData('is_active', value)
                            }
                        />
                    </div>
                </div>
                <div className="space-y-2">
                    {form.data.options.map((option, index) => (
                        <div
                            key={option.client_key}
                            className="grid grid-cols-[minmax(0,1fr)_64px_44px] gap-2 rounded-xl border border-[#eeeeee] p-2 sm:grid-cols-[minmax(0,1fr)_112px_64px_44px] sm:border-0 sm:p-0"
                        >
                            <input
                                aria-label={`Option ${index + 1} name`}
                                placeholder="Option name"
                                className={`${controlClass} col-span-3 sm:col-span-1`}
                                value={option.name}
                                onChange={(event) =>
                                    updateOption(index, {
                                        ...option,
                                        name: event.target.value,
                                    })
                                }
                            />
                            {form.data.semantic_role === 'instruction' ? (
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
                                        aria-label={`Option ${index + 1} price adjustment`}
                                        className={`${controlClass} pl-7`}
                                        value={option.price_delta}
                                        onChange={(event) =>
                                            updateOption(index, {
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
                                    updateOption(index, {
                                        ...option,
                                        is_active: !option.is_active,
                                    })
                                }
                            >
                                {option.is_active ? 'On' : 'Off'}
                            </button>
                            <button
                                type="button"
                                aria-label={`Remove option ${index + 1}`}
                                className="flex size-11 items-center justify-center rounded-xl border border-red-200 bg-white text-red-700"
                                onClick={() =>
                                    form.setData(
                                        'options',
                                        form.data.options.filter(
                                            (_, optionIndex) =>
                                                optionIndex !== index,
                                        ),
                                    )
                                }
                            >
                                <Trash2 className="size-4" />
                            </button>
                            {form.errors[`options.${index}.name`] && (
                                <p className="col-span-full text-xs text-red-700">
                                    {form.errors[`options.${index}.name`]}
                                </p>
                            )}
                        </div>
                    ))}
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} w-full border-dashed`}
                        onClick={() =>
                            form.setData('options', [
                                ...form.data.options,
                                {
                                    id: null,
                                    client_key: crypto.randomUUID(),
                                    name: '',
                                    price_delta: '0.00',
                                    sort_order: form.data.options.length,
                                    is_active: true,
                                },
                            ])
                        }
                    >
                        <Plus className="size-4" /> Add option
                    </Button>
                </div>
            </fieldset>
            <FormErrors errors={form.errors} />
            <SaveButton
                processing={form.processing}
                label={group ? 'Save changes' : 'Add Group'}
            />
        </form>
    );
}

function AssignGroupForm({
    group,
    products,
    onSaved,
}: {
    group: ModifierGroup;
    products: CatalogChoice[];
    onSaved: () => void;
}) {
    const form = useForm({ product_ids: group.product_ids ?? [] });
    const submitting = useRef(false);

    return (
        <form
            className="flex flex-col gap-4"
            aria-busy={form.processing}
            onSubmit={(event) => {
                event.preventDefault();
                if (submitting.current) return;
                submitting.current = true;
                form.submit(updateGroupProducts(group.id), {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Group assignments saved');
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
                className="grid max-h-[50dvh] gap-2 overflow-y-auto sm:grid-cols-2"
            >
                {products.map((product) => {
                    const selected = form.data.product_ids.includes(product.id);

                    return (
                        <label
                            key={product.id}
                            className={`flex min-h-14 cursor-pointer items-center gap-3 rounded-xl border p-3 ${selected ? 'border-neutral-950 bg-neutral-50' : 'border-neutral-200 bg-white'}`}
                        >
                            <input
                                type="checkbox"
                                checked={selected}
                                className="size-5 shrink-0 accent-neutral-950"
                                onChange={() =>
                                    form.setData(
                                        'product_ids',
                                        selected
                                            ? form.data.product_ids.filter(
                                                  (id) => id !== product.id,
                                              )
                                            : [
                                                  ...form.data.product_ids,
                                                  product.id,
                                              ],
                                    )
                                }
                            />
                            <span className="min-w-0 flex-1">
                                <span className="block text-[13px] font-semibold break-words">
                                    {product.name}
                                </span>
                                {!product.is_active && (
                                    <span className="block text-[11px] text-neutral-500">
                                        Inactive
                                    </span>
                                )}
                            </span>
                        </label>
                    );
                })}
            </fieldset>
            {products.length === 0 && (
                <p className="rounded-xl border border-dashed border-neutral-300 p-5 text-center text-sm text-neutral-500">
                    No Products are available to assign.
                </p>
            )}
            <FormErrors errors={form.errors} />
            <Button
                type="submit"
                disabled={form.processing}
                className="min-h-12 rounded-xl bg-neutral-950 font-bold text-white hover:bg-neutral-800"
            >
                <Check className="size-4" />
                {form.processing ? 'Saving…' : 'Save assignment'}
            </Button>
        </form>
    );
}
