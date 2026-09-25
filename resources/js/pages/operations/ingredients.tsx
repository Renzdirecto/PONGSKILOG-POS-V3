import { Info, Pencil, Plus, Search, X } from 'lucide-react';
import { useState } from 'react';
import { IngredientDialog } from '@/components/operations-ingredient-dialog';
import {
    Chip,
    EmptyState,
    IngredientIcon,
    OperationsShell,
    Segmented,
    StatusChip,
    StockBar,
    formatQuantityOrDash,
    opsButtonClass,
    opsLabelClass,
    opsPrimaryClass,
    purchaseUnitLabel,
} from '@/components/operations-ui';
import { formatPeso, formatQuantity } from '@/lib/operations';
import type {
    OperationsContext,
    OperationsIngredient,
} from '@/types/operations';

type Props = {
    operations: OperationsContext;
    ingredients: OperationsIngredient[];
};

const updated = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

export default function OperationsIngredients({
    operations,
    ingredients,
}: Props) {
    const planId = operations.active_plan_id;
    const plan = operations.plans.find((item) => item.id === planId);
    const [scope, setScope] = useState<'plan' | 'all'>(plan ? 'plan' : 'all');
    const [query, setQuery] = useState('');
    const [editing, setEditing] = useState<OperationsIngredient | 'new' | null>(
        null,
    );
    /** Ingredient definitions are shared by every Branch; only business-wide Operations edits them. */
    const canEdit = operations.can_manage_definitions;
    const planName = (id: string) =>
        operations.plans.find((item) => item.id === id)?.name ?? 'Plan';
    const inPlan = ingredients.filter(
        (ingredient) => planId && ingredient.plan_ids.includes(planId),
    );
    const search = query.trim().toLowerCase();
    const list = (scope === 'plan' ? inPlan : ingredients).filter(
        (ingredient) =>
            !search || ingredient.name.toLowerCase().includes(search),
    );
    const shared = inPlan.filter(
        (ingredient) => ingredient.plan_ids.length > 1,
    );

    return (
        <OperationsShell
            operations={operations}
            title="Ingredients"
            description={`Ingredient records${plan ? ` used by the ${plan.name} plan` : ''}. Each is one branch stock record, shared with any other plan that uses it.`}
            action={
                canEdit ? (
                    <button
                        type="button"
                        className={opsPrimaryClass}
                        onClick={() => setEditing('new')}
                        disabled={operations.plans.length === 0}
                    >
                        <Plus className="size-4" /> Add ingredient
                    </button>
                ) : undefined
            }
        >
            <div className="flex flex-wrap items-center gap-2">
                {plan && (
                    <div className="min-w-0 flex-[1_1_280px]">
                        <Segmented
                            label="Ingredient scope"
                            value={scope}
                            onChange={setScope}
                            options={[
                                {
                                    value: 'plan',
                                    label: `${plan.name} plan · ${inPlan.length}`,
                                },
                                {
                                    value: 'all',
                                    label: `All ingredients · ${ingredients.length}`,
                                },
                            ]}
                        />
                    </div>
                )}
                <label className="flex h-11 min-w-0 flex-[1_1_220px] items-center gap-2 rounded-[10px] border border-[#d8d8d8] bg-white px-3">
                    <Search
                        className="size-4 shrink-0 text-[#767676]"
                        aria-hidden="true"
                    />
                    <input
                        type="search"
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search ingredients"
                        aria-label="Search ingredients"
                        className="h-full min-w-0 flex-1 bg-transparent text-base outline-none sm:text-[13.5px]"
                    />
                    {query && (
                        <button
                            type="button"
                            aria-label="Clear search"
                            onClick={() => setQuery('')}
                            className="flex size-9 items-center justify-center rounded-lg text-[#767676]"
                        >
                            <X className="size-4" />
                        </button>
                    )}
                </label>
            </div>
            <p className="flex items-start gap-2 rounded-xl bg-[#f7f7f7] px-3 py-2.5 text-xs leading-5 text-[#444]">
                <Info className="mt-0.5 size-4 shrink-0" aria-hidden="true" />
                <span>
                    Each ingredient is one branch stock record.{' '}
                    {shared.length
                        ? `${shared.map((ingredient) => ingredient.name).join(', ')} ${shared.length === 1 ? 'is' : 'are'} shared with another plan, so ${shared.length === 1 ? 'its' : 'their'} stock is the same number in both.`
                        : "None of this plan's ingredients are shared yet."}
                    {!operations.branch &&
                        ' Stock shows after you choose a Branch.'}
                </span>
            </p>

            {operations.plans.length === 0 ? (
                <EmptyState
                    title="Create a plan first"
                    body="Ingredients are shown in plans. Add a plan in Pamalengke Plans, then add its ingredients here."
                />
            ) : list.length === 0 ? (
                <EmptyState
                    title={
                        search
                            ? `No ingredient matches “${query}”.`
                            : 'This plan has no ingredients yet.'
                    }
                    body={
                        search
                            ? 'Try another name or show every ingredient.'
                            : 'Add an ingredient, or open All ingredients and add this plan to an existing one.'
                    }
                    action={
                        <button
                            type="button"
                            className={opsButtonClass}
                            onClick={() => {
                                setQuery('');
                                setScope('all');
                            }}
                        >
                            Show all ingredients
                        </button>
                    }
                />
            ) : (
                <div className="overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white">
                    <table className="hidden w-full text-left min-[980px]:table">
                        <thead className="bg-[#fafafa]">
                            <tr>
                                {[
                                    'Ingredient',
                                    'Stock / target',
                                    'Purchase unit',
                                    'Replenishment rule',
                                    'Used in',
                                    'Status',
                                    '',
                                ].map((label) => (
                                    <th
                                        key={label || 'actions'}
                                        scope="col"
                                        className={`px-3.5 py-2.5 ${opsLabelClass}`}
                                    >
                                        {label || (
                                            <span className="sr-only">
                                                Actions
                                            </span>
                                        )}
                                    </th>
                                ))}
                            </tr>
                        </thead>
                        <tbody>
                            {list.map((ingredient) => (
                                <tr
                                    key={ingredient.id}
                                    className="border-t border-[#f2f2f2] align-middle"
                                >
                                    <td className="px-3.5 py-3">
                                        <span className="flex min-w-0 items-center gap-2.5">
                                            <IngredientIcon
                                                icon={ingredient.icon}
                                            />
                                            <span className="flex min-w-0 flex-col">
                                                <span
                                                    className="max-w-[220px] truncate text-[13.5px] font-semibold"
                                                    title={ingredient.name}
                                                >
                                                    {ingredient.name}
                                                </span>
                                                <span className="text-[11px] text-[#767676]">
                                                    Base unit:{' '}
                                                    {ingredient.base_unit}
                                                </span>
                                            </span>
                                        </span>
                                    </td>
                                    <td className="w-[180px] px-3.5 py-3">
                                        <span className="flex flex-col gap-1.5">
                                            <span className="text-[13px] whitespace-nowrap tabular-nums">
                                                <strong>
                                                    {ingredient.stock
                                                        ?.current ?? '—'}
                                                </strong>
                                                <span className="text-[#8a8a8a]">
                                                    {' '}
                                                    /{' '}
                                                    {formatQuantity(
                                                        ingredient.target,
                                                        ingredient.base_unit,
                                                    )}
                                                </span>
                                            </span>
                                            <StockBar ingredient={ingredient} />
                                        </span>
                                    </td>
                                    <td className="px-3.5 py-3">
                                        <span className="flex flex-col">
                                            <span className="text-[12.5px] font-semibold">
                                                {purchaseUnitLabel(ingredient)}
                                            </span>
                                            <CostLine ingredient={ingredient} />
                                        </span>
                                    </td>
                                    <td className="max-w-[200px] px-3.5 py-3 text-xs leading-5 text-[#555]">
                                        {ingredient.rule_label}
                                    </td>
                                    <td className="px-3.5 py-3">
                                        <span className="flex flex-wrap gap-1">
                                            {ingredient.plan_ids.map((id) => (
                                                <Chip
                                                    key={id}
                                                    tone={
                                                        id === planId
                                                            ? 'dark'
                                                            : 'plain'
                                                    }
                                                >
                                                    {planName(id)}
                                                </Chip>
                                            ))}
                                        </span>
                                    </td>
                                    <td className="px-3.5 py-3">
                                        {ingredient.status ? (
                                            <StatusChip
                                                ingredient={ingredient}
                                            />
                                        ) : (
                                            <Chip tone="outline">
                                                Choose a branch
                                            </Chip>
                                        )}
                                    </td>
                                    <td className="px-3.5 py-3 text-right">
                                        {canEdit && (
                                            <button
                                                type="button"
                                                className={opsButtonClass}
                                                onClick={() =>
                                                    setEditing(ingredient)
                                                }
                                            >
                                                <Pencil className="size-4" />{' '}
                                                Edit
                                            </button>
                                        )}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                    <ul className="flex flex-col min-[980px]:hidden">
                        {list.map((ingredient) => (
                            <li
                                key={ingredient.id}
                                className="flex flex-col gap-2.5 border-b border-[#f2f2f2] p-3 last:border-b-0"
                            >
                                <div className="flex items-start gap-2.5">
                                    <IngredientIcon icon={ingredient.icon} />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="text-sm leading-snug font-semibold wrap-anywhere">
                                            {ingredient.name}
                                        </span>
                                        <span className="text-[11px] text-[#767676]">
                                            Base unit: {ingredient.base_unit}
                                            {ingredient.updated_at
                                                ? ` · Updated ${updated.format(new Date(ingredient.updated_at))}`
                                                : ''}
                                        </span>
                                    </span>
                                    {ingredient.status && (
                                        <StatusChip ingredient={ingredient} />
                                    )}
                                </div>
                                <div className="flex flex-col gap-1.5">
                                    <span className="flex justify-between text-[13px] tabular-nums">
                                        <span className="text-[#767676]">
                                            Stock / target
                                        </span>
                                        <span>
                                            <strong>
                                                {formatQuantityOrDash(
                                                    ingredient.stock?.current,
                                                    ingredient.base_unit,
                                                )}
                                            </strong>
                                            <span className="text-[#8a8a8a]">
                                                {' '}
                                                /{' '}
                                                {formatQuantity(
                                                    ingredient.target,
                                                    ingredient.base_unit,
                                                )}
                                            </span>
                                        </span>
                                    </span>
                                    <StockBar ingredient={ingredient} />
                                </div>
                                <div className="grid grid-cols-2 gap-2.5 rounded-[10px] bg-[#fafafa] p-2.5">
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className={opsLabelClass}>
                                            Purchase unit
                                        </span>
                                        <span className="text-[12.5px] font-semibold">
                                            {purchaseUnitLabel(ingredient)}
                                        </span>
                                        <CostLine ingredient={ingredient} />
                                    </span>
                                    <span className="flex min-w-0 flex-col gap-0.5">
                                        <span className={opsLabelClass}>
                                            Rule
                                        </span>
                                        <span className="text-xs leading-snug text-[#444]">
                                            {ingredient.rule_label}
                                        </span>
                                    </span>
                                </div>
                                <div className="flex items-center gap-2">
                                    <span className="flex min-w-0 flex-1 flex-wrap items-center gap-1">
                                        <span className="text-[11px] text-[#767676]">
                                            Used in
                                        </span>
                                        {ingredient.plan_ids.map((id) => (
                                            <Chip
                                                key={id}
                                                tone={
                                                    id === planId
                                                        ? 'dark'
                                                        : 'plain'
                                                }
                                            >
                                                {planName(id)}
                                            </Chip>
                                        ))}
                                    </span>
                                    {canEdit && (
                                        <button
                                            type="button"
                                            className={opsButtonClass}
                                            onClick={() =>
                                                setEditing(ingredient)
                                            }
                                        >
                                            <Pencil className="size-4" /> Edit
                                        </button>
                                    )}
                                </div>
                            </li>
                        ))}
                    </ul>
                </div>
            )}

            {editing && (
                <IngredientDialog
                    ingredient={editing === 'new' ? null : editing}
                    operations={operations}
                    onClose={() => setEditing(null)}
                />
            )}
        </OperationsShell>
    );
}

function CostLine({ ingredient }: { ingredient: OperationsIngredient }) {
    const unit = ingredient.purchase_unit;
    if (!unit) {
        return (
            <span className="text-[11.5px] font-semibold text-[#b45309]">
                No purchase unit
            </span>
        );
    }
    if (unit.cost_cents === null) {
        return (
            <span className="text-[11.5px] font-semibold text-[#b45309]">
                Cost unknown
            </span>
        );
    }

    return (
        <span className="text-[11.5px] text-[#767676] tabular-nums">
            {formatPeso(unit.cost_cents)} per {unit.name}
        </span>
    );
}
