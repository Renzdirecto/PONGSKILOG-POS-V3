import { useForm } from '@inertiajs/react';
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
    panelClass,
    primaryActionClass,
    SaveButton,
    Status,
    TextField,
} from '@/components/catalog-ui';
import { Button } from '@/components/ui/button';
import { store, update } from '@/routes/modifier-groups';
import {
    store as storeOption,
    update as updateOption,
} from '@/routes/modifier-options';
import type { ModifierGroup, ModifierOption } from '@/types/catalog';

export default function Modifiers({ groups }: { groups: ModifierGroup[] }) {
    const [editing, setEditing] = useState<ModifierGroup | null | undefined>();
    const [optionEditor, setOptionEditor] = useState<{
        group: ModifierGroup;
        option: ModifierOption | null;
    } | null>(null);
    return (
        <CatalogPage
            tab="Modifiers"
            action={
                <Button
                    className={primaryActionClass}
                    onClick={() => setEditing(null)}
                >
                    Add group
                </Button>
            }
        >
            {groups.length === 0 ? (
                <div className={panelClass}>
                    No modifier groups yet. Create a group, then add options
                    such as extra rice or egg.
                </div>
            ) : (
                <div className="grid items-start gap-5 lg:grid-cols-2">
                    {groups.map((group) => (
                        <section
                            key={group.id}
                            className={`${panelClass} flex flex-col gap-4`}
                        >
                            <div className="flex flex-wrap items-center justify-between gap-3">
                                <h2 className="min-w-0 text-xl font-bold break-words">
                                    {group.name}
                                </h2>
                                <Status active={group.is_active} />
                            </div>
                            <p className="text-sm text-neutral-500">
                                {group.selection_type === 'single'
                                    ? 'Single choice'
                                    : 'Multiple choices'}{' '}
                                · Select {group.min_select}–{group.max_select}
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
                                <ul className="divide-y divide-neutral-100">
                                    {group.options.map((option) => (
                                        <li
                                            key={option.id}
                                            className="flex flex-wrap items-center gap-3 py-3"
                                        >
                                            <div className="min-w-0 flex-1">
                                                <p className="font-semibold break-words">
                                                    {option.name}
                                                </p>
                                                <p className="text-sm text-neutral-500">
                                                    +{money(option.price_delta)}
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
                title={editing ? 'Edit modifier group' : 'Add modifier group'}
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
                        toast.success('Modifier group saved');
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
                    id="group-selection"
                    label="Selection type"
                    error={form.errors.selection_type}
                >
                    <select
                        id="group-selection"
                        className={controlClass}
                        value={form.data.selection_type}
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
                        <option value="single">Single choice</option>
                        <option value="multiple">Multiple choices</option>
                    </select>
                </Field>
                <div className="grid grid-cols-2 gap-4">
                    <TextField
                        id="group-min"
                        label="Minimum choices"
                        type="number"
                        value={form.data.min_select}
                        onChange={(value) => form.setData('min_select', value)}
                        error={form.errors.min_select}
                    />
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
                label={group ? 'Save changes' : 'Add group'}
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
        price_delta: option?.price_delta ?? '0.00',
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
                form.submit(option ? updateOption(option.id) : storeOption(), {
                    preserveScroll: true,
                    onSuccess: () => {
                        toast.success('Modifier option saved');
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
                <TextField
                    id="option-price"
                    label="Additional price (₱)"
                    value={form.data.price_delta}
                    onChange={(value) => form.setData('price_delta', value)}
                    error={form.errors.price_delta}
                />
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
