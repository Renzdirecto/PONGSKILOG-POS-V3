import { Link, router } from '@inertiajs/react';
import {
    ArrowRight,
    Archive,
    BarChart3,
    Box,
    Check,
    ListChecks,
    Leaf,
    Pencil,
    Plus,
    RefreshCw,
    ShoppingCart,
} from 'lucide-react';
import { useState } from 'react';
import {
    Chip,
    EmptyState,
    OperationsDialog,
    OperationsShell,
    PLAN_ICON_NAMES,
    PlanIcon,
    formatQuantityOrDash,
    opsButtonClass,
    opsCardClass,
    opsInputClass,
    opsLabelClass,
    opsPrimaryClass,
    operationsHref,
} from '@/components/operations-ui';
import { formatPeso } from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type {
    OperationsContext,
    OperationsFigures,
    OperationsIngredient,
    OperationsPlan,
    OperationsSummaryProps,
} from '@/types/operations';

type PlanCard = {
    id: string;
    products: number;
    products_needing_recipe: number;
    products_with_recipe: number;
    ingredients: number;
    shared_ingredients: number;
    below_target: number | null;
    to_buy: number | null;
    suggested_cents: number | null;
    suggested_unknown: number;
    figures: OperationsFigures | null;
};

type PickerProduct = {
    id: string;
    name: string;
    category: string | null;
    plan_id: string | null;
};

type Props = {
    operations: OperationsContext;
    cards: PlanCard[];
    summary: OperationsSummaryProps;
    outside: { count: number; examples: string[] };
    shared: (OperationsIngredient & { plan_ids: string[] }) | null;
    products: PickerProduct[];
};

const PARTS: [typeof Box, string][] = [
    [Box, 'Products'],
    [ListChecks, 'Recipes'],
    [Leaf, 'Ingredients'],
    [RefreshCw, 'Replenishment rules'],
    [ShoppingCart, 'Purchasing recommendations'],
    [BarChart3, 'Reporting'],
];

export default function OperationsPlans({
    operations,
    cards,
    summary,
    outside,
    shared,
    products,
}: Props) {
    const [editing, setEditing] = useState<OperationsPlan | 'new' | null>(null);
    /** Plans are shared by every Branch; only business-wide Operations edits them. */
    const canEdit = operations.can_manage_definitions;
    const planName = (id: string) =>
        operations.plans.find((plan) => plan.id === id)?.name ?? 'Plan';
    const business = summary.business;

    return (
        <OperationsShell
            operations={operations}
            title="Pamalengke Plans"
            description="Plans group products, recipes, ingredients and market planning. Ingredient stock stays shared per branch."
            action={
                canEdit ? (
                    <button
                        type="button"
                        className={opsPrimaryClass}
                        onClick={() => setEditing('new')}
                    >
                        <Plus className="size-4" /> Add plan
                    </button>
                ) : undefined
            }
        >
            <section className={opsCardClass} aria-labelledby="plan-holds">
                <div className="flex flex-col gap-1">
                    <h2
                        id="plan-holds"
                        className="text-[14.5px] font-bold tracking-[-0.01em]"
                    >
                        What a plan holds
                    </h2>
                    <p className="max-w-[80ch] text-[12.5px] leading-5 text-[#555]">
                        A plan groups the products you sell, the recipes that
                        connect them to ingredients, the replenishment rules and
                        the market list, so each product family gets its own
                        planning and reporting. A plan does not hold stock of
                        its own.
                    </p>
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {PARTS.map(([Icon, label]) => (
                        <span
                            key={label}
                            className="inline-flex h-7 items-center gap-1.5 rounded-full bg-[#f2f2f2] px-2.5 text-[11.5px] font-semibold text-[#333]"
                        >
                            <Icon className="size-[13px]" aria-hidden="true" />
                            {label}
                        </span>
                    ))}
                </div>
            </section>

            {operations.plans.length === 0 ? (
                <EmptyState
                    title="No plans yet"
                    body="Create a plan for a product family, such as Drinks or Silog. Then attach recipes to its existing Catalog products and add the ingredients they use."
                    action={
                        canEdit ? (
                            <button
                                type="button"
                                className={opsPrimaryClass}
                                onClick={() => setEditing('new')}
                            >
                                <Plus className="size-4" /> Add the first plan
                            </button>
                        ) : undefined
                    }
                />
            ) : (
                <div className="grid grid-cols-1 gap-2.5 min-[620px]:grid-cols-2 min-[1400px]:grid-cols-3 md:gap-3">
                    {operations.plans.map((plan) => {
                        const card = cards.find((item) => item.id === plan.id);
                        if (!card) {
                            return null;
                        }
                        const missing =
                            card.products_needing_recipe -
                            card.products_with_recipe;

                        return (
                            <article
                                key={plan.id}
                                className={`${opsCardClass} gap-3`}
                            >
                                <div className="flex items-start gap-3">
                                    <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-[#111] text-white">
                                        <PlanIcon
                                            icon={plan.icon}
                                            className="size-5"
                                        />
                                    </span>
                                    <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                        <h2 className="text-base font-bold tracking-[-0.015em] wrap-anywhere">
                                            {plan.name} plan
                                        </h2>
                                        <span className="text-xs leading-5 text-[#666]">
                                            {plan.description ??
                                                `Ingredients, recipes and market planning for ${plan.name}.`}
                                        </span>
                                    </span>
                                    {canEdit && (
                                        <button
                                            type="button"
                                            aria-label={`Edit ${plan.name} plan`}
                                            className={`${opsButtonClass} w-11 px-0`}
                                            onClick={() => setEditing(plan)}
                                        >
                                            <Pencil className="size-4" />
                                        </button>
                                    )}
                                </div>
                                <dl className="grid grid-cols-2 gap-px overflow-hidden rounded-xl border border-[#efefef] bg-[#efefef]">
                                    <Stat
                                        label="Products with recipes"
                                        value={`${card.products_with_recipe} of ${card.products_needing_recipe}`}
                                        note={
                                            missing > 0
                                                ? `${missing} missing a recipe`
                                                : 'All set'
                                        }
                                        warn={missing > 0}
                                    />
                                    <Stat
                                        label="Ingredients"
                                        value={String(card.ingredients)}
                                        note={
                                            card.shared_ingredients
                                                ? `${card.shared_ingredients} shared with another plan`
                                                : 'None shared'
                                        }
                                    />
                                    <Stat
                                        label="Low stock"
                                        value={
                                            card.below_target === null
                                                ? '—'
                                                : String(card.below_target)
                                        }
                                        note={
                                            card.to_buy === null
                                                ? 'Choose a branch'
                                                : `${card.to_buy} to buy now`
                                        }
                                        warn={(card.to_buy ?? 0) > 0}
                                    />
                                    <Stat
                                        label="Suggested market cost"
                                        value={
                                            card.suggested_cents === null
                                                ? '—'
                                                : formatPeso(
                                                      card.suggested_cents,
                                                      true,
                                                  )
                                        }
                                        note={
                                            card.suggested_cents === null
                                                ? 'Choose a branch'
                                                : card.suggested_unknown
                                                  ? `Estimated · ${card.suggested_unknown} without a cost`
                                                  : 'Estimated, today'
                                        }
                                    />
                                </dl>
                                <div className="mt-auto flex gap-2">
                                    <Link
                                        href={operationsHref(
                                            'overview',
                                            plan.id,
                                        )}
                                        className={`${opsPrimaryClass} flex-1`}
                                    >
                                        Open plan{' '}
                                        <ArrowRight className="size-4" />
                                    </Link>
                                    <Link
                                        href={operationsHref(
                                            'pamamalengke',
                                            plan.id,
                                        )}
                                        className={opsButtonClass}
                                    >
                                        <ShoppingCart className="size-4" />{' '}
                                        Pamamalengke
                                    </Link>
                                </div>
                            </article>
                        );
                    })}
                    <button
                        type="button"
                        hidden={!canEdit}
                        onClick={() => setEditing('new')}
                        className="flex min-h-[200px] flex-col items-center justify-center gap-2 rounded-2xl border-[1.5px] border-dashed border-[#c9c9c9] bg-[#fafafa] p-4 text-center hover:border-[#111] hover:bg-white focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                    >
                        <span className="flex size-10 items-center justify-center rounded-xl border border-[#d8d8d8] bg-white">
                            <Plus className="size-4" />
                        </span>
                        <span className="text-sm font-bold">Add plan</span>
                        <span className="max-w-[34ch] text-xs leading-5 text-[#767676]">
                            Group another product family, like Rice meals, with
                            its own recipes and market list.
                        </span>
                    </button>
                </div>
            )}

            {operations.plans.length > 0 && (
                <section
                    className={opsCardClass}
                    aria-labelledby="today-across"
                >
                    <div className="flex flex-col gap-0.5">
                        <h2
                            id="today-across"
                            className="text-[14.5px] font-bold"
                        >
                            Today across plans
                        </h2>
                        <p className="text-xs text-[#767676]">
                            Business date {summary.business_date} ·{' '}
                            {operations.branch
                                ? `${operations.branch.name} branch`
                                : 'All Branches'}
                            . Estimated from recipes and the costs recorded when
                            each sale was made.
                        </p>
                    </div>
                    <div className="hidden overflow-hidden rounded-xl border border-[#efefef] min-[820px]:block">
                        <table className="w-full text-left">
                            <thead className="bg-[#fafafa]">
                                <tr>
                                    {[
                                        'Plan',
                                        'Sales',
                                        'Est. COGS',
                                        'Est. gross profit',
                                        'Suggested market cost',
                                    ].map((label, index) => (
                                        <th
                                            key={label}
                                            scope="col"
                                            className={`px-3.5 py-2.5 ${opsLabelClass} ${index ? 'text-right' : ''}`}
                                        >
                                            {label}
                                        </th>
                                    ))}
                                </tr>
                            </thead>
                            <tbody>
                                {operations.plans.map((plan) => {
                                    const card = cards.find(
                                        (item) => item.id === plan.id,
                                    );
                                    const figures = card?.figures;

                                    return (
                                        <tr
                                            key={plan.id}
                                            className="border-t border-[#f2f2f2] hover:bg-[#fafafa]"
                                        >
                                            <th
                                                scope="row"
                                                className="px-3.5 py-3 text-[13.5px] font-semibold"
                                            >
                                                <Link
                                                    href={operationsHref(
                                                        'overview',
                                                        plan.id,
                                                    )}
                                                    className="inline-flex items-center gap-2 hover:underline"
                                                >
                                                    <PlanIcon
                                                        icon={plan.icon}
                                                        className="size-4"
                                                    />
                                                    {plan.name} plan
                                                </Link>
                                            </th>
                                            <td className="px-3.5 py-3 text-right text-[13.5px] font-semibold tabular-nums">
                                                {formatPeso(
                                                    figures?.sales_cents ?? 0,
                                                    true,
                                                )}
                                            </td>
                                            <td className="px-3.5 py-3 text-right text-[13.5px] text-[#555] tabular-nums">
                                                {formatPeso(
                                                    figures?.cogs_cents ?? 0,
                                                    true,
                                                )}
                                                {figures?.incomplete && (
                                                    <span className="block text-[10.5px] text-[#b45309]">
                                                        Incomplete
                                                    </span>
                                                )}
                                            </td>
                                            <td className="px-3.5 py-3 text-right text-[13.5px] font-bold tabular-nums">
                                                {formatPeso(
                                                    figures?.gross_profit_cents ??
                                                        0,
                                                    true,
                                                )}
                                            </td>
                                            <td className="px-3.5 py-3 text-right text-[13.5px] font-semibold text-[#7a5710] tabular-nums">
                                                {card?.suggested_cents ===
                                                    null || card === undefined
                                                    ? '—'
                                                    : formatPeso(
                                                          card.suggested_cents,
                                                          true,
                                                      )}
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>
                    <ul className="flex flex-col gap-2 min-[820px]:hidden">
                        {operations.plans.map((plan) => {
                            const card = cards.find(
                                (item) => item.id === plan.id,
                            );
                            const figures = card?.figures;

                            return (
                                <li key={plan.id}>
                                    <Link
                                        href={operationsHref(
                                            'overview',
                                            plan.id,
                                        )}
                                        className="flex flex-col gap-2 rounded-xl border border-[#e5e5e5] p-3"
                                    >
                                        <span className="flex items-center gap-2 text-[13.5px] font-bold">
                                            <PlanIcon
                                                icon={plan.icon}
                                                className="size-4"
                                            />
                                            <span className="flex-1">
                                                {plan.name} plan
                                            </span>
                                            <ArrowRight className="size-4 text-[#767676]" />
                                        </span>
                                        <span className="grid grid-cols-2 gap-x-2.5 gap-y-1.5">
                                            <MiniValue
                                                label="Sales"
                                                value={formatPeso(
                                                    figures?.sales_cents ?? 0,
                                                    true,
                                                )}
                                            />
                                            <MiniValue
                                                label="Est. COGS"
                                                value={formatPeso(
                                                    figures?.cogs_cents ?? 0,
                                                    true,
                                                )}
                                            />
                                            <MiniValue
                                                label="Est. gross profit"
                                                value={formatPeso(
                                                    figures?.gross_profit_cents ??
                                                        0,
                                                    true,
                                                )}
                                            />
                                            <MiniValue
                                                label="Market cost"
                                                value={
                                                    card?.suggested_cents ==
                                                    null
                                                        ? '—'
                                                        : formatPeso(
                                                              card.suggested_cents,
                                                              true,
                                                          )
                                                }
                                            />
                                        </span>
                                    </Link>
                                </li>
                            );
                        })}
                    </ul>
                    <dl className="ml-auto flex w-full max-w-[560px] flex-col">
                        <TotalRow
                            label="Business net sales"
                            value={formatPeso(business.sales_cents)}
                            sub="Same Net Sales as Reports"
                        />
                        <TotalRow
                            label="Estimated ingredient COGS"
                            value={`−${formatPeso(business.cogs_cents)}`}
                            sub={
                                business.uncosted_sales_cents > 0
                                    ? `${formatPeso(business.uncosted_sales_cents)} of sales is not costed`
                                    : undefined
                            }
                        />
                        <TotalRow
                            label="Estimated gross profit"
                            value={formatPeso(business.gross_profit_cents)}
                            strong
                        />
                        <TotalRow
                            label="Non-stock supplies bought today"
                            value={`−${formatPeso(business.non_stock_cents)}`}
                            sub="Manual pamamalengke items"
                        />
                        <TotalRow
                            label="Other store expenses"
                            value={`−${formatPeso(business.other_expenses_cents)}`}
                            sub="Other Store Purchases / Expenses, counted once"
                        />
                        <TotalRow
                            label="Business estimated operating profit"
                            value={formatPeso(business.operating_profit_cents)}
                            total
                        />
                    </dl>
                    <p className="text-[11.5px] leading-5 text-[#767676]">
                        {outside.count > 0
                            ? `${outside.count} active product${outside.count === 1 ? ' is' : 's are'} outside every plan (for example ${outside.examples.slice(0, 2).join(' and ')}). ${formatPeso(summary.outside_plan_sales_cents)} of today's sales is outside plan metrics and uncosted.`
                            : 'Every active product belongs to a plan.'}
                        {business.incomplete
                            ? ' Estimates are incomplete where recipes or costs are missing; missing costs are never treated as ₱0.'
                            : ''}
                    </p>
                </section>
            )}

            {shared && (
                <section className={opsCardClass} aria-labelledby="one-record">
                    <div className="flex flex-col gap-0.5">
                        <h2 id="one-record" className="text-[14.5px] font-bold">
                            One stock record per ingredient
                        </h2>
                        <p className="max-w-[80ch] text-xs leading-5 text-[#666]">
                            Plans choose which ingredients they show. They never
                            copy stock. {shared.name} is used by{' '}
                            {shared.plan_ids.map(planName).join(' and ')} and
                            has one branch record, so buying it on either plan's
                            run covers both.
                        </p>
                    </div>
                    <div className="grid grid-cols-1 gap-2 sm:grid-cols-[minmax(0,1fr)_minmax(0,1.25fr)]">
                        <div className="flex flex-col gap-0.5 rounded-xl bg-[#f7f7f7] p-3">
                            <span className={opsLabelClass}>Used in</span>
                            <span className="flex flex-wrap gap-1.5 pt-1">
                                {shared.plan_ids.map((id) => (
                                    <Chip key={id} tone="plain">
                                        {planName(id)} plan
                                    </Chip>
                                ))}
                            </span>
                        </div>
                        <div className="flex flex-col gap-0.5 rounded-xl border-[1.5px] border-[#111] p-3">
                            <span className={opsLabelClass}>
                                {shared.name} · one record
                            </span>
                            <span className="text-lg font-bold tabular-nums">
                                {formatQuantityOrDash(
                                    shared.stock?.current,
                                    shared.base_unit,
                                )}
                            </span>
                            <span className="text-[11px] text-[#767676]">
                                {operations.branch
                                    ? `${operations.branch.name} branch stock`
                                    : 'Choose a branch to see its stock'}{' '}
                                · target{' '}
                                {formatQuantityOrDash(
                                    shared.target,
                                    shared.base_unit,
                                )}
                            </span>
                        </div>
                    </div>
                </section>
            )}

            {editing && (
                <PlanDialog
                    plan={editing === 'new' ? null : editing}
                    products={products}
                    plans={operations.plans}
                    onClose={() => setEditing(null)}
                />
            )}
        </OperationsShell>
    );
}

function Stat({
    label,
    value,
    note,
    warn = false,
}: {
    label: string;
    value: string;
    note: string;
    warn?: boolean;
}) {
    return (
        <div className="flex min-w-0 flex-col gap-0.5 bg-[#fafafa] px-3 py-2.5">
            <dt className={opsLabelClass}>{label}</dt>
            <dd className="text-[17px] font-bold tracking-[-0.02em] tabular-nums">
                {value}
            </dd>
            <dd
                className={`text-[11px] leading-4 ${warn ? 'font-semibold text-[#b45309]' : 'text-[#8a8a8a]'}`}
            >
                {note}
            </dd>
        </div>
    );
}

function MiniValue({ label, value }: { label: string; value: string }) {
    return (
        <span className="flex min-w-0 flex-col">
            <span className={opsLabelClass}>{label}</span>
            <span className="text-sm font-bold tabular-nums">{value}</span>
        </span>
    );
}

function TotalRow({
    label,
    value,
    sub,
    strong = false,
    total = false,
}: {
    label: string;
    value: string;
    sub?: string;
    strong?: boolean;
    total?: boolean;
}) {
    return (
        <div
            className={`flex items-center gap-2.5 ${total ? 'mt-0.5 border-t-[1.5px] border-[#111] pt-2.5' : strong ? 'border-y border-t-[#c9c9c9] border-b-[#f2f2f2] py-2' : 'border-b border-[#f2f2f2] py-2'}`}
        >
            <dt className="flex min-w-0 flex-1 flex-col">
                <span
                    className={
                        strong || total
                            ? 'text-[13.5px] font-bold'
                            : 'text-[13px] font-medium'
                    }
                >
                    {label}
                </span>
                {sub && (
                    <span className="text-[11px] text-[#8a8a8a]">{sub}</span>
                )}
            </dt>
            <dd
                className={`shrink-0 font-bold whitespace-nowrap tabular-nums ${total ? 'text-lg' : 'text-[13.5px]'}`}
            >
                {value}
            </dd>
        </div>
    );
}

function PlanDialog({
    plan,
    products,
    plans,
    onClose,
}: {
    plan: OperationsPlan | null;
    products: PickerProduct[];
    plans: OperationsPlan[];
    onClose: () => void;
}) {
    const [name, setName] = useState(plan?.name ?? '');
    const [description, setDescription] = useState(plan?.description ?? '');
    const [icon, setIcon] = useState(plan?.icon ?? 'bowl');
    const [selected, setSelected] = useState<string[]>(
        plan
            ? products
                  .filter((product) => product.plan_id === plan.id)
                  .map((product) => product.id)
            : [],
    );
    const [query, setQuery] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const planName = (id: string | null) =>
        plans.find((item) => item.id === id)?.name;
    const visible = products.filter((product) =>
        product.name.toLowerCase().includes(query.trim().toLowerCase()),
    );
    const moving = selected.filter((id) => {
        const current = products.find((product) => product.id === id)?.plan_id;

        return current && current !== plan?.id;
    }).length;

    const save = () => {
        if (!name.trim()) {
            setErrors({ name: 'Enter a plan name.' });

            return;
        }
        setBusy(true);
        const payload = {
            name: name.trim(),
            description: description.trim() || null,
            icon,
            product_ids: selected,
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (next: Record<string, string>) => setErrors(next),
            onFinish: () => setBusy(false),
        };
        if (plan) {
            router.put(
                operationsRoutes.plans.update.url(plan.id),
                payload,
                options,
            );
        } else {
            router.post(operationsRoutes.plans.store.url(), payload, options);
        }
    };

    return (
        <OperationsDialog
            open
            busy={busy}
            onClose={onClose}
            kicker="Pamalengke Plans"
            title={plan ? `Edit ${plan.name} plan` : 'Add plan'}
            footer={
                <div className="flex flex-col gap-2">
                    {Object.values(errors).length > 0 && (
                        <p
                            role="alert"
                            className="text-xs font-semibold text-[#b91c1c]"
                        >
                            {Object.values(errors)[0]}
                        </p>
                    )}
                    <div className="flex gap-2">
                        {plan && (
                            <button
                                type="button"
                                className={opsButtonClass}
                                disabled={busy}
                                onClick={() => {
                                    if (
                                        window.confirm(
                                            `Archive the ${plan.name} plan? Its history stays in reports; its products are released.`,
                                        )
                                    ) {
                                        setBusy(true);
                                        router.post(
                                            operationsRoutes.plans.archive.url(
                                                plan.id,
                                            ),
                                            {},
                                            { onFinish: () => setBusy(false) },
                                        );
                                    }
                                }}
                            >
                                <Archive className="size-4" /> Archive
                            </button>
                        )}
                        <button
                            type="button"
                            className={`${opsButtonClass} flex-1`}
                            onClick={onClose}
                            disabled={busy}
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            className={`${opsPrimaryClass} flex-1`}
                            onClick={save}
                            disabled={busy}
                        >
                            <Check className="size-4" />{' '}
                            {plan ? 'Save plan' : 'Add plan'}
                        </button>
                    </div>
                </div>
            }
        >
            <div className="flex flex-col gap-4">
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Plan name</span>
                    <input
                        className={opsInputClass}
                        value={name}
                        maxLength={60}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Rice meals"
                    />
                </label>
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>
                        Description (optional)
                    </span>
                    <input
                        className={opsInputClass}
                        value={description}
                        maxLength={200}
                        onChange={(event) => setDescription(event.target.value)}
                        placeholder="Rice meal ingredients, recipes and market planning."
                    />
                </label>
                <fieldset className="flex flex-col gap-1.5">
                    <legend className={`${opsLabelClass} pb-1.5`}>Icon</legend>
                    <div className="flex flex-wrap gap-1.5">
                        {PLAN_ICON_NAMES.map((name) => (
                            <button
                                key={name}
                                type="button"
                                aria-label={name}
                                aria-pressed={icon === name}
                                onClick={() => setIcon(name)}
                                className={`flex size-11 items-center justify-center rounded-[10px] border ${icon === name ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white'}`}
                            >
                                <PlanIcon icon={name} />
                            </button>
                        ))}
                    </div>
                </fieldset>
                <fieldset className="flex flex-col gap-1.5">
                    <legend className="flex w-full justify-between gap-2 pb-1.5">
                        <span className={opsLabelClass}>Existing products</span>
                        <span className="text-[11.5px] text-[#767676]">
                            {selected.length} selected
                        </span>
                    </legend>
                    <p className="text-[11.5px] leading-5 text-[#767676]">
                        From Catalog › Products. A product belongs to one plan
                        at a time; choosing one that is in another plan moves it
                        for future sales only.
                    </p>
                    <input
                        className={opsInputClass}
                        value={query}
                        onChange={(event) => setQuery(event.target.value)}
                        placeholder="Search products"
                        aria-label="Search products"
                    />
                    <div className="grid max-h-[280px] grid-cols-1 gap-1.5 overflow-y-auto sm:grid-cols-2">
                        {visible.map((product) => {
                            const on = selected.includes(product.id);
                            const inPlan =
                                product.plan_id && product.plan_id !== plan?.id
                                    ? planName(product.plan_id)
                                    : null;

                            return (
                                <button
                                    key={product.id}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() =>
                                        setSelected((current) =>
                                            on
                                                ? current.filter(
                                                      (id) => id !== product.id,
                                                  )
                                                : [...current, product.id],
                                        )
                                    }
                                    className={`flex min-h-12 items-center gap-2.5 rounded-[10px] px-2.5 py-1.5 text-left ${on ? 'border-[1.5px] border-[#111]' : 'border border-[#e5e5e5]'}`}
                                >
                                    <span
                                        className={`flex size-5 shrink-0 items-center justify-center rounded-md ${on ? 'bg-[#111] text-white' : 'border-[1.5px] border-[#c9c9c9]'}`}
                                    >
                                        {on && <Check className="size-3.5" />}
                                    </span>
                                    <span className="flex min-w-0 flex-col">
                                        <span className="truncate text-[13px] font-semibold">
                                            {product.name}
                                        </span>
                                        <span className="truncate text-[11px] text-[#767676]">
                                            {[
                                                product.category,
                                                inPlan
                                                    ? `in ${inPlan} plan`
                                                    : null,
                                            ]
                                                .filter(Boolean)
                                                .join(' · ')}
                                        </span>
                                    </span>
                                </button>
                            );
                        })}
                        {visible.length === 0 && (
                            <p className="text-xs text-[#767676]">
                                No active product matches.
                            </p>
                        )}
                    </div>
                    {moving > 0 && (
                        <p className="text-[11.5px] font-semibold text-[#b45309]">
                            {moving} product{moving === 1 ? '' : 's'} will move
                            from another plan. Past sales keep their original
                            plan.
                        </p>
                    )}
                </fieldset>
            </div>
        </OperationsDialog>
    );
}
