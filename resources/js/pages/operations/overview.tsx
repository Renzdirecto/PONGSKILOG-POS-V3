import { Link } from '@inertiajs/react';
import { ArrowRight, ChartColumn, ShoppingCart } from 'lucide-react';
import { useState } from 'react';
import {
    Chip,
    MovementList,
    OperationsShell,
    SummaryDialog,
    opsButtonClass,
    opsCardClass,
    opsLabelClass,
    opsPrimaryClass,
    operationsHref,
} from '@/components/operations-ui';
import {
    formatPeso,
    formatQuantity,
    parseSignedQuantity,
    unitLabel,
} from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type {
    EarlierPurchase,
    IngredientMovementGroup,
    ManualEntry,
    MarketPlan,
    OperationsContext,
    OperationsFigures,
    OperationsIngredient,
    OperationsSummaryProps,
    RecipeState,
} from '@/types/operations';

type Props = {
    operations: OperationsContext;
    figures: OperationsFigures | null;
    business_date: string;
    ingredients: OperationsIngredient[];
    market: MarketPlan & { manual: ManualEntry[] };
    recipes: {
        id: string;
        name: string;
        state: RecipeState;
    }[];
    consumption: (OperationsIngredient & { used: string })[];
    movements: IngredientMovementGroup[];
    summary: OperationsSummaryProps;
    earlier: EarlierPurchase[];
};

const RECIPE_STATE: Record<
    Props['recipes'][number]['state'],
    [string, string]
> = {
    set: ['Recipe set', 'bg-[#15803d]'],
    partial: ['Some sizes missing', 'bg-[#b45309]'],
    missing: ['No recipe · not costed', 'bg-[#b45309]'],
    not_needed: ['No recipe needed', 'bg-[#8a8a8a]'],
    product_stock: ['Uses Product stock', 'bg-[#8a8a8a]'],
    configuration_error: ['Size groups need fixing', 'bg-[#b91c1c]'],
};

export default function OperationsOverview(props: Props) {
    const { operations, figures, ingredients, market, recipes } = props;
    const [summaryTab, setSummaryTab] = useState<'market' | 'profit' | null>(
        null,
    );
    const plan = operations.plans.find(
        (item) => item.id === operations.active_plan_id,
    )!;
    const byId = new Map(
        ingredients.map((ingredient) => [ingredient.id, ingredient]),
    );
    const autoOn = market.auto
        .filter((item) => !item.skipped)
        .map((item) => byId.get(item.ingredient_id)!)
        .filter(Boolean);
    const allAuto = market.auto
        .map((item) => byId.get(item.ingredient_id)!)
        .filter(Boolean);
    const missing = recipes.filter((recipe) => recipe.state === 'missing');
    const below = ingredients.filter(
        (ingredient) =>
            ingredient.status &&
            ['negative', 'out', 'buy', 'below'].includes(ingredient.status.key),
    );
    const sales = figures?.sales_cents ?? 0;
    const margin =
        sales > 0 && figures
            ? Math.round((figures.gross_profit_cents / sales) * 100)
            : 0;
    const planHref = (page: Parameters<typeof operationsHref>[0]) =>
        operationsHref(page, plan.id);
    const attention = [
        ...ingredients
            .filter(
                (ingredient) =>
                    ingredient.status?.key === 'negative' ||
                    ingredient.status?.key === 'out',
            )
            .map((ingredient) => ({
                key: `out-${ingredient.id}`,
                tone: 'bg-[#b91c1c]',
                name:
                    ingredient.status?.key === 'negative'
                        ? `${ingredient.name} is below zero · count needed`
                        : `${ingredient.name} is out of stock`,
                sub: `Current ${formatQuantity(ingredient.stock?.current ?? '0', ingredient.base_unit)}`,
                action: 'Stock',
                href: planHref('stock'),
            })),
        ...autoOn.map((ingredient) => ({
            key: `buy-${ingredient.id}`,
            tone: 'bg-[#c8962e]',
            name: ingredient.name,
            sub: `${formatQuantity(ingredient.stock?.current ?? '0', ingredient.base_unit)} left · buy ${ingredient.recommendation?.units} ${unitLabel(ingredient.purchase_unit?.name ?? '', ingredient.recommendation?.units ?? 0)}`,
            action: 'Plan',
            href: planHref('pamamalengke'),
        })),
        ...ingredients
            .filter((ingredient) => ingredient.status?.key === 'setup')
            .map((ingredient) => ({
                key: `setup-${ingredient.id}`,
                tone: 'bg-[#b45309]',
                name: `${ingredient.name} needs setup`,
                sub: 'No purchase unit yet',
                action: 'Set up',
                href: planHref('ingredients'),
            })),
        ...missing.map((recipe) => ({
            key: `recipe-${recipe.id}`,
            tone: 'bg-[#b45309]',
            name: `${recipe.name} has no recipe`,
            sub: 'Sales move no stock and add no cost',
            action: 'Recipe',
            href: operationsRoutes.recipes({
                query: { plan: plan.id, product: recipe.id },
            }),
        })),
    ];
    const kpis: {
        label: string;
        value: string;
        note: string;
        href: ReturnType<typeof operationsHref>;
        tone?: 'gold' | 'warn';
    }[] = [
        {
            label: 'Sales today',
            value: formatPeso(sales, true),
            note: `${figures?.items ?? 0} items · ${figures?.orders ?? 0} orders`,
            href: planHref('stock'),
        },
        {
            label: 'Est. COGS',
            value: formatPeso(figures?.cogs_cents ?? 0, true),
            note: figures?.uncosted_sales_cents
                ? `${formatPeso(figures.uncosted_sales_cents, true)} of sales not costed`
                : 'From recorded recipe costs',
            href: planHref('recipes'),
        },
        {
            label: 'Est. gross profit',
            value: formatPeso(figures?.gross_profit_cents ?? 0, true),
            note:
                sales > 0
                    ? `${margin}% of sales${figures?.incomplete ? ' · incomplete' : ''}`
                    : 'No sales yet',
            href: planHref('overview'),
        },
        {
            label: 'Est. market cost',
            value: operations.branch
                ? formatPeso(market.estimate_cents, true)
                : '—',
            note: operations.branch
                ? `Next run · ${autoOn.length + market.manual.length} items${market.unknown ? ` · ${market.unknown} without a cost` : ''}`
                : 'Choose a branch',
            href: planHref('pamamalengke'),
            tone: 'gold',
        },
        {
            label: 'Ingredients below target',
            value: operations.branch ? String(below.length) : '—',
            note: `of ${ingredients.length} in this plan`,
            href: planHref('stock'),
        },
        {
            label: 'Auto recommended',
            value: operations.branch ? String(allAuto.length) : '—',
            note: 'Purchase rule reached',
            href: planHref('pamamalengke'),
        },
        {
            label: 'Products missing recipe',
            value: String(missing.length),
            note: missing.length
                ? missing.map((recipe) => recipe.name).join(', ')
                : 'Every product is covered',
            href: planHref('recipes'),
            tone: missing.length ? 'warn' : undefined,
        },
    ];

    return (
        <OperationsShell
            operations={operations}
            title="Overview"
            description={`${plan.name} plan: today's sales, estimated cost, stock that needs attention and the next market run.`}
            badge={autoOn.length}
        >
            <div className="grid grid-cols-2 gap-2 min-[520px]:grid-cols-3 min-[900px]:grid-cols-4">
                {kpis.map((kpi) => (
                    <Link
                        key={kpi.label}
                        href={kpi.href}
                        className={`flex min-w-0 flex-col items-start gap-0.5 rounded-[13px] border p-2.5 md:p-3 ${kpi.tone === 'gold' ? 'border-[#ead7a4] bg-[#fbf6e9]' : kpi.tone === 'warn' ? 'border-[#ebd3a3] bg-white' : 'border-[#e5e5e5] bg-white'} focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none`}
                    >
                        <span className={opsLabelClass}>{kpi.label}</span>
                        <span className="text-[19px] leading-tight font-bold tracking-[-0.02em] tabular-nums md:text-[22px]">
                            {kpi.value}
                        </span>
                        <span className="text-[11px] leading-4 wrap-anywhere text-[#8a8a8a]">
                            {kpi.note}
                        </span>
                    </Link>
                ))}
            </div>
            <p className="text-[11px] leading-4 text-[#8a8a8a]">
                Business date {props.business_date}. Estimated values use recipe
                quantities and each ingredient's cost at the moment of sale;
                missing recipes or costs are never counted as ₱0.
            </p>

            <div className="grid grid-cols-1 items-start gap-2.5 min-[760px]:grid-cols-2 md:gap-3">
                <section className={opsCardClass} aria-labelledby="attention">
                    <h2 id="attention" className="text-[14.5px] font-bold">
                        Needs attention
                    </h2>
                    {!operations.branch && (
                        <p className="text-[12.5px] text-[#767676]">
                            Stock attention needs one Branch. Choose one in the
                            header.
                        </p>
                    )}
                    {attention.length === 0 ? (
                        <p className="text-[12.5px] leading-5 text-[#767676]">
                            Nothing needs attention. Every ingredient is above
                            its rule and every product has a recipe.
                        </p>
                    ) : (
                        <ul className="flex flex-col">
                            {attention.map((item) => (
                                <li
                                    key={item.key}
                                    className="flex min-h-[50px] items-center gap-2.5 border-t border-[#f2f2f2] py-1"
                                >
                                    <span
                                        className={`size-[9px] shrink-0 rounded-full ${item.tone}`}
                                        aria-hidden="true"
                                    />
                                    <span className="flex min-w-0 flex-1 flex-col">
                                        <span className="text-[13px] font-semibold wrap-anywhere">
                                            {item.name}
                                        </span>
                                        <span className="text-[11.5px] text-[#767676] tabular-nums">
                                            {item.sub}
                                        </span>
                                    </span>
                                    <Link
                                        href={item.href}
                                        className={opsButtonClass}
                                    >
                                        {item.action}
                                    </Link>
                                </li>
                            ))}
                        </ul>
                    )}
                </section>

                <section className={opsCardClass} aria-labelledby="next-run">
                    <div className="flex items-start justify-between gap-2.5">
                        <div className="flex min-w-0 flex-col gap-0.5">
                            <h2 id="next-run" className={opsLabelClass}>
                                Upcoming pamamalengke · estimated
                            </h2>
                            <span className="text-2xl font-bold tracking-[-0.025em] tabular-nums">
                                {operations.branch
                                    ? formatPeso(market.estimate_cents)
                                    : '—'}
                            </span>
                            <span className="text-[11.5px] text-[#767676]">
                                {autoOn.length} auto · {market.manual.length}{' '}
                                manual
                                {market.unknown
                                    ? ` · ${market.unknown} without a cost`
                                    : ''}
                            </span>
                        </div>
                        <span className="flex size-10 items-center justify-center rounded-xl bg-[#fbf6e9] text-[#7a5710]">
                            <ShoppingCart className="size-5" />
                        </span>
                    </div>
                    <ul className="flex flex-col">
                        {autoOn.map((ingredient) => (
                            <li
                                key={ingredient.id}
                                className="flex items-center gap-2.5 border-t border-[#f2f2f2] py-2"
                            >
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <span className="text-[13px] font-semibold">
                                            {ingredient.name}
                                        </span>
                                        <Chip tone="gold">Auto</Chip>
                                    </span>
                                    <span className="text-[11.5px] text-[#767676] tabular-nums">
                                        {ingredient.recommendation?.units}{' '}
                                        {unitLabel(
                                            ingredient.purchase_unit?.name ??
                                                '',
                                            ingredient.recommendation?.units ??
                                                0,
                                        )}
                                    </span>
                                </span>
                                <span className="text-[13px] font-semibold whitespace-nowrap tabular-nums">
                                    {ingredient.recommendation
                                        ?.estimate_cents == null
                                        ? 'Cost unknown'
                                        : formatPeso(
                                              ingredient.recommendation
                                                  .estimate_cents,
                                          )}
                                </span>
                            </li>
                        ))}
                        {market.manual.map((entry) => (
                            <li
                                key={entry.id}
                                className="flex items-center gap-2.5 border-t border-[#f2f2f2] py-2"
                            >
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="flex flex-wrap items-center gap-1.5">
                                        <span className="text-[13px] font-semibold">
                                            {entry.name}
                                        </span>
                                        <Chip tone="plain">Manual</Chip>
                                    </span>
                                    <span className="text-[11.5px] text-[#767676] tabular-nums">
                                        {formatQuantity(
                                            entry.quantity,
                                            entry.unit,
                                        )}
                                    </span>
                                </span>
                                <span className="text-[13px] font-semibold whitespace-nowrap tabular-nums">
                                    {entry.estimate_cents === null
                                        ? 'Cost unknown'
                                        : formatPeso(entry.estimate_cents)}
                                </span>
                            </li>
                        ))}
                    </ul>
                    {autoOn.length === 0 && market.manual.length === 0 && (
                        <p className="text-[12.5px] text-[#767676]">
                            {operations.branch
                                ? 'No purchase suggested right now.'
                                : 'Choose a branch to see suggestions.'}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <Link
                            href={planHref('pamamalengke')}
                            className={`${opsPrimaryClass} flex-1`}
                        >
                            Open Pamamalengke
                        </Link>
                        <button
                            type="button"
                            className={opsButtonClass}
                            onClick={() => setSummaryTab('market')}
                        >
                            <ChartColumn className="size-4" /> Summary
                        </button>
                    </div>
                </section>
            </div>

            <div className="grid grid-cols-1 items-start gap-2.5 min-[760px]:grid-cols-2 md:gap-3">
                <section className={opsCardClass} aria-labelledby="consumption">
                    <div className="flex flex-col gap-0.5">
                        <h2
                            id="consumption"
                            className="text-[14.5px] font-bold"
                        >
                            Today's consumption
                        </h2>
                        <p className="text-xs text-[#767676]">
                            What this plan's sales took from branch stock today.
                        </p>
                    </div>
                    {props.consumption.length === 0 ? (
                        <p className="text-[12.5px] text-[#767676]">
                            {operations.branch
                                ? 'No recipe-linked sales yet today.'
                                : 'Choose a branch to see its consumption.'}
                        </p>
                    ) : (
                        <ul className="flex flex-col gap-2.5">
                            {props.consumption.map((ingredient) => {
                                const available = ingredient.stock
                                    ? parseSignedQuantity(
                                          ingredient.stock.start,
                                      ) +
                                      parseSignedQuantity(
                                          ingredient.stock.purchased,
                                      )
                                    : 0;
                                const base =
                                    available > 0
                                        ? Math.min(
                                              100,
                                              (parseSignedQuantity(
                                                  ingredient.used,
                                              ) /
                                                  available) *
                                                  100,
                                          )
                                        : 0;

                                return (
                                    <li
                                        key={ingredient.id}
                                        className="flex flex-col gap-1.5"
                                    >
                                        <span className="flex items-center gap-2">
                                            <span className="min-w-0 flex-1 truncate text-[13px] font-semibold">
                                                {ingredient.name}
                                            </span>
                                            {ingredient.plan_ids.length > 1 && (
                                                <Chip tone="outline">
                                                    Shared
                                                </Chip>
                                            )}
                                            <span className="text-[12.5px] font-bold whitespace-nowrap text-[#b91c1c] tabular-nums">
                                                −
                                                {formatQuantity(
                                                    ingredient.used,
                                                    ingredient.base_unit,
                                                )}
                                            </span>
                                        </span>
                                        <span
                                            aria-hidden="true"
                                            className="relative block h-1.5 overflow-hidden rounded-full bg-[#efefef]"
                                        >
                                            <span
                                                className="absolute inset-y-0 left-0 rounded-full bg-[#b91c1c]"
                                                style={{
                                                    width: `${base.toFixed(1)}%`,
                                                }}
                                            />
                                        </span>
                                        <span className="text-[11px] text-[#8a8a8a] tabular-nums">
                                            {formatQuantity(
                                                ingredient.stock?.current ??
                                                    '0',
                                                ingredient.base_unit,
                                            )}{' '}
                                            left
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
                <section
                    className={opsCardClass}
                    aria-labelledby="top-products"
                >
                    <div className="flex flex-col gap-0.5">
                        <h2
                            id="top-products"
                            className="text-[14.5px] font-bold"
                        >
                            Top-selling products
                        </h2>
                        <p className="text-xs text-[#767676]">
                            Existing Catalog products in this plan, by sales
                            today.
                        </p>
                    </div>
                    {!figures || figures.products.length === 0 ? (
                        <p className="text-[12.5px] text-[#767676]">
                            No sales in this plan yet today.
                        </p>
                    ) : (
                        <ul className="flex flex-col">
                            {figures.products.slice(0, 6).map((product) => {
                                const state =
                                    recipes.find(
                                        (recipe) =>
                                            recipe.id === product.product_id,
                                    )?.state ??
                                    (product.state === 'recipe'
                                        ? 'set'
                                        : product.state === 'not_needed'
                                          ? 'not_needed'
                                          : 'missing');
                                const [label, tone] = RECIPE_STATE[state];

                                return (
                                    <li
                                        key={product.product_id}
                                        className="flex min-h-[46px] items-center gap-2.5 border-t border-[#f2f2f2] py-1 first:border-t-0"
                                    >
                                        <span
                                            className={`size-2 shrink-0 rounded-full ${tone}`}
                                            aria-hidden="true"
                                        />
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="text-[13px] font-semibold">
                                                {product.name}
                                            </span>
                                            <span className="text-[11.5px] text-[#767676]">
                                                {product.quantity} sold ·{' '}
                                                {label}
                                            </span>
                                        </span>
                                        <span className="text-[13.5px] font-bold whitespace-nowrap tabular-nums">
                                            {formatPeso(
                                                product.sales_cents,
                                                true,
                                            )}
                                        </span>
                                    </li>
                                );
                            })}
                        </ul>
                    )}
                </section>
            </div>

            <section className={opsCardClass} aria-labelledby="recent-moves">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <div className="flex min-w-0 flex-col gap-0.5">
                        <h2
                            id="recent-moves"
                            className="text-[14.5px] font-bold"
                        >
                            Recent ingredient movements
                        </h2>
                        <p className="text-xs text-[#767676]">
                            Newest first. Movements are appended, never
                            rewritten.
                        </p>
                    </div>
                    <Link href={planHref('stock')} className={opsButtonClass}>
                        All movements <ArrowRight className="size-4" />
                    </Link>
                </div>
                <MovementList
                    movements={props.movements}
                    planId={plan.id}
                    empty={
                        operations.branch
                            ? 'No ingredient movements yet today.'
                            : 'Choose a branch to see its movements.'
                    }
                />
            </section>

            {summaryTab && (
                <SummaryDialog
                    open
                    onClose={() => setSummaryTab(null)}
                    initialTab={summaryTab}
                    planName={plan.name}
                    summary={props.summary}
                    auto={
                        operations.branch
                            ? autoOn.map((ingredient) => ({
                                  name: ingredient.name,
                                  quantity: `${ingredient.recommendation?.units} ${unitLabel(ingredient.purchase_unit?.name ?? '', ingredient.recommendation?.units ?? 0)}`,
                                  estimate_cents:
                                      ingredient.recommendation
                                          ?.estimate_cents ?? null,
                              }))
                            : null
                    }
                    manual={market.manual}
                    earlier={props.earlier}
                />
            )}
        </OperationsShell>
    );
}
