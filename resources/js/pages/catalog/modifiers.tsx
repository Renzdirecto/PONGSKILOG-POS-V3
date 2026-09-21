import { useForm, usePage } from '@inertiajs/react';
import { SlidersHorizontal } from 'lucide-react';
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
import { store, update } from '@/routes/modifier-groups';
import {
    store as storeOption,
    update as updateOption,
} from '@/routes/modifier-options';
import type { ModifierGroup, ModifierOption } from '@/types/catalog';

export default function Modifiers({ groups }: { groups: ModifierGroup[] }) {
    const createRequested = usePage().url.includes('create=group');
    const [editing, setEditing] = useState<ModifierGroup | null | undefined>(
        createRequested ? null : undefined,
    );
    const [optionEditor, setOptionEditor] = useState<{
        group: ModifierGroup;
        option: ModifierOption | null;
    } | null>(null);
    return (
        <CatalogPage
            tab="Groups"
            counts={{ Groups: groups.length }}
        >
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
                                    : `${group.semantic_role === 'size' ? 'Size' : 'Standard options'} · ${group.selection_type === 'single' ? 'One choice' : 'Multiple choices'} · Select ${group.min_select}–${group.max_select}`}
                            </p>
                            <div className="flex gap-2">
                                <Button
                                    variant="outline"
                                    className={actionClass}
                                    onClick={() => setEditing(group)}
                                >
                                    Edit group
                                </Button>
                                <Button
                                    variant="outline"
                                    className={actionClass}
                                    onClick={() =>
                                        setOptionEditor({ group, option: null })
                                    }
                                >
                                    Add option
                                </Button>
                            </div>
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
                                            <Button
                                                variant="outline"
                                                className={actionClass}
                                                onClick={() =>
                                                    setOptionEditor({
                                                        group,
                                                        option,
                                                    })
                                                }
                                            >
                                                Edit
                                                <span className="sr-only">
                                                    {' '}
                                                    {option.name}
                                                </span>
                                            </Button>
                                        </li>
                                    ))}
                                </ul>
                            )}
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
                open={optionEditor !== null}
                onClose={() => setOptionEditor(null)}
                title={optionEditor?.option ? 'Edit option' : 'Add option'}
                description={`Options for ${optionEditor?.group.name ?? 'this group'}.`}
            >
                {optionEditor && (
                    <OptionForm
                        key={optionEditor.option?.id ?? optionEditor.group.id}
                        {...optionEditor}
                        onSaved={() => setOptionEditor(null)}
                    />
                )}
            </CatalogDialog>
        </CatalogPage>
    );
}

function GroupForm({
    group,
    onSaved,
}: {
    group: ModifierGroup | null;
    onSaved: () => void;
}) {
    const form = useForm({
        name: group?.name ?? '',
        semantic_role: group?.semantic_role ?? null,
        selection_type: group?.selection_type ?? 'single',
        min_select: String(group?.min_select ?? 0),
        max_select: String(group?.max_select ?? 1),
        is_active: group?.is_active ?? true,
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
                <TextField
                    id="group-name"
                    label="Name"
                    value={form.data.name}
                    onChange={(value) => form.setData('name', value)}
                    error={form.errors.name}
                />
                <Field
                    id="group-semantic-role"
                    label="Behavior"
                    error={form.errors.semantic_role}
                >
                    <select
                        id="group-semantic-role"
                        className={controlClass}
                        value={form.data.semantic_role ?? ''}
                        onChange={(event) => {
                            const role =
                                event.target.value === 'size'
                                    ? 'size'
                                    : event.target.value === 'instruction'
                                      ? 'instruction'
                                      : null;
                            form.setData((data) => ({
                                ...data,
                                semantic_role: role,
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
                        <option value="">Standard options</option>
                        <option value="size">Size</option>
                        <option value="instruction">Instructions</option>
                    </select>
                </Field>
                <Field
                    id="group-selection"
                    label="Selection type"
                    error={form.errors.selection_type}
                >
                    <select
                        id="group-selection"
                        className={controlClass}
                        value={form.data.selection_type}
                        disabled={form.data.semantic_role === 'instruction'}
                        onChange={(event) => {
                            const type = event.target.value as
                                | 'single'
                                | 'multiple';
                            form.setData('selection_type', type);
                            if (type === 'single') {
                                form.setData('max_select', '1');
                                form.setData(
                                    'min_select',
                                    String(
                                        Math.min(
                                            Number(form.data.min_select),
                                            1,
                                        ),
                                    ),
                                );
                            }
                        }}
                    >
                        <option value="single">One choice</option>
                        <option value="multiple">Multiple choices</option>
                    </select>
                </Field>
                {form.data.semantic_role === 'instruction' && (
                    <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Instructions are optional preparation choices. They
                        allow multiple selections and never change the Product
                        name or price.
                    </p>
                )}
                <div className="grid grid-cols-2 gap-4">
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
                </div>
                <ActiveField
                    value={form.data.is_active}
                    onChange={(value) => form.setData('is_active', value)}
                />
            </fieldset>
            <FormErrors errors={form.errors} />
            <SaveButton
                processing={form.processing}
                label={group ? 'Save changes' : 'Add Group'}
            />
        </form>
    );
}

function OptionForm({
    group,
    option,
    onSaved,
}: {
    group: ModifierGroup;
    option: ModifierOption | null;
    onSaved: () => void;
}) {
    const form = useForm({
        modifier_group_id: group.id,
        name: option?.name ?? '',
        price_delta:
            group.semantic_role === 'instruction'
                ? '0.00'
                : (option?.price_delta ?? '0.00'),
        sort_order: String(option?.sort_order ?? 0),
        is_active: option?.is_active ?? true,
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
                form.transform((data) => ({
                    ...data,
                    price_delta:
                        group.semantic_role === 'instruction'
                            ? '0.00'
                            : data.price_delta,
                }));
                form.submit(option ? updateOption(option.id) : storeOption(), {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Option saved');
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
                    id="option-name"
                    label="Name"
                    value={form.data.name}
                    onChange={(value) => form.setData('name', value)}
                    error={form.errors.name}
                />
                {group.semantic_role === 'instruction' ? (
                    <p className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900">
                        Instruction options are fixed at ₱0.00 and do not
                        affect order totals.
                    </p>
                ) : (
                    <TextField
                        id="option-price"
                        label="Additional price (₱)"
                        value={form.data.price_delta}
                        onChange={(value) => form.setData('price_delta', value)}
                        error={form.errors.price_delta}
                    />
                )}
                <TextField
                    id="option-sort"
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
                label={option ? 'Save changes' : 'Add option'}
            />
        </form>
    );
}
