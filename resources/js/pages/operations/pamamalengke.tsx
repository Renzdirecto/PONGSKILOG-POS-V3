import { Link, router, usePage } from '@inertiajs/react';
import {
    ChartColumn,
    Check,
    ChevronDown,
    Layers,
    ListChecks,
    Minus,
    Pencil,
    Plus,
    ShoppingCart,
    Trash2,
    TriangleAlert,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { IngredientDialog } from '@/components/operations-ingredient-dialog';
import {
    BranchRequired,
    Chip,
    EmptyState,
    IngredientIcon,
    OperationsDialog,
    OperationsShell,
    Segmented,
    StatusChip,
    StockBar,
    SummaryDialog,
    opsButtonClass,
    opsCardClass,
    opsInputClass,
    opsLabelClass,
    opsPrimaryClass,
    operationsHref,
    purchaseUnitLabel,
} from '@/components/operations-ui';
import { createClientUuid } from '@/lib/client-uuid';
import {
    boughtLines,
    checklistStorageKey,
    clearChecklist,
    defaultLine,
    emptyChecklist,
    formatPeso,
    formatQuantity,
    formatScaled,
    lineTotalCents,
    parseMoney,
    parseQuantity,
    readChecklist,
    unitLabel,
    writeChecklist,
} from '@/lib/operations';
import type {
    ChecklistItem,
    ChecklistLine,
    ChecklistState,
} from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type {
    EarlierPurchase,
    ManualEntry,
    MarketPlan,
    OperationsContext,
    OperationsIngredient,
    OperationsSummaryProps,
} from '@/types/operations';

type Props = {
    operations: OperationsContext;
    mode: 'plan' | 'shop';
    ingredients: OperationsIngredient[];
    market: MarketPlan;
    manual: ManualEntry[];
    summary: OperationsSummaryProps;
    earlier: EarlierPurchase[];
};

export default function OperationsPamamalengke(props: Props) {
    const { operations, ingredients, market, manual } = props;
    const plan = operations.plans.find(
        (item) => item.id === operations.active_plan_id,
    )!;
    const branch = operations.branch;
    const [mode, setMode] = useState<'plan' | 'shop'>(props.mode);
    const [showOk, setShowOk] = useState(false);
    const [summaryOpen, setSummaryOpen] = useState(false);
    const [manualOpen, setManualOpen] = useState(false);
    const [editing, setEditing] = useState<OperationsIngredient | null>(null);
    const [reviewOpen, setReviewOpen] = useState(false);
    const byId = useMemo(
        () =>
            new Map(
                ingredients.map((ingredient) => [ingredient.id, ingredient]),
            ),
        [ingredients],
    );
    const auto = market.auto
        .map((item) => ({ ...item, ingredient: byId.get(item.ingredient_id)! }))
        .filter((item) => item.ingredient);
    const autoOn = auto.filter((item) => !item.skipped);
    const setup = market.setup.map((id) => byId.get(id)!).filter(Boolean);
    const ok = market.ok.map((id) => byId.get(id)!).filter(Boolean);
    const manualEstimate = manual.reduce(
        (sum, entry) => sum + (entry.estimate_cents ?? 0),
        0,
    );
    const estimate = market.estimate_cents + manualEstimate;
    const unknown =
        market.unknown +
        manual.filter((entry) => entry.estimate_cents === null).length;

    const storageKey = branch ? checklistStorageKey(branch.id, plan.id) : null;
    const [checklist, setChecklist] = useState<ChecklistState>(() =>
        storageKey ? readChecklist(storageKey) : emptyChecklist(),
    );
    useEffect(() => {
        if (storageKey) {
            writeChecklist(storageKey, checklist);
        }
    }, [storageKey, checklist]);
    /** A confirmed run clears this device's checklist and its idempotency key. */
    const flash = usePage().flash as {
        pamamalengkeConfirmed?: { purchase_id: string };
    };
    useEffect(() => {
        if (flash?.pamamalengkeConfirmed && storageKey) {
            clearChecklist(storageKey);
            setChecklist(emptyChecklist());
        }
    }, [flash?.pamamalengkeConfirmed, storageKey]);

    const items: ChecklistItem[] = [
        ...autoOn.map(({ ingredient }) => ({
            key: `i:${ingredient.id}`,
            type: 'ingredient' as const,
            ingredientId: ingredient.id,
            entryId: null,
            name: ingredient.name,
            unit: ingredient.purchase_unit?.name ?? ingredient.base_unit,
            planned: String(ingredient.recommendation?.units ?? 1),
            estimatedUnitCents: ingredient.purchase_unit?.cost_cents ?? null,
        })),
        ...manual.map((entry) => ({
            key: `m:${entry.id}`,
            type: 'manual' as const,
            ingredientId: null,
            entryId: entry.id,
            name: entry.name,
            unit: entry.unit,
            planned: entry.quantity,
            estimatedUnitCents: entry.estimated_unit_cost_cents,
        })),
    ];
    const bought = boughtLines(items, checklist);
    const actual = bought.reduce(
        (sum, item) => sum + (item.totalCents ?? 0),
        0,
    );
    const estimateBought = bought.reduce(
        (sum, item) => sum + (item.estimateCents ?? 0),
        0,
    );
    const unavailable = items.filter(
        (item) => checklist.lines[item.key]?.unavailable,
    ).length;
    const patch = (item: ChecklistItem, change: Partial<ChecklistLine>) =>
        setChecklist((current) => ({
            ...current,
            lines: {
                ...current.lines,
                [item.key]: {
                    ...(current.lines[item.key] ?? defaultLine(item)),
                    ...change,
                },
            },
        }));
    const switchMode = (next: 'plan' | 'shop') => {
        setMode(next);
        router.replace({
            url: operationsRoutes.pamamalengke.url({
                query: {
                    plan: plan.id,
                    ...(next === 'shop' ? { mode: 'shop' } : {}),
                },
            }),
            preserveState: true,
            preserveScroll: true,
        });
    };
    const blocked = !branch
        ? 'Choose a Branch first.'
        : bought.length === 0
          ? 'Mark at least one item as bought.'
          : null;

    return (
        <OperationsShell
            operations={operations}
            title="Pamamalengke"
            description={`What to buy on the next market run for the ${plan.name} plan, from stock, targets and purchase rules.`}
            badge={autoOn.length}
        >
            {!branch ? (
                <BranchRequired what="Pamamalengke works from ingredient stock, which" />
            ) : (
                <>
                    <section
                        className={opsCardClass}
                        aria-labelledby="market-total"
                    >
                        <div className="flex flex-wrap items-start justify-between gap-2.5">
                            <div className="flex min-w-0 flex-col gap-0.5">
                                <span className={opsLabelClass}>
                                    {plan.name} plan · Next market run
                                </span>
                                <h2
                                    id="market-total"
                                    className="text-[26px] font-bold tracking-[-0.025em] tabular-nums"
                                >
                                    {formatPeso(estimate)}
                                </h2>
                                <span className="text-[11.5px] leading-5 text-[#767676]">
                                    Estimated from the latest purchase prices
                                    {unknown
                                        ? `, plus ${unknown} item${unknown === 1 ? '' : 's'} without a known cost`
                                        : ''}
                                    .
                                </span>
                            </div>
                            <div className="flex max-w-[460px] min-w-0 flex-[1_1_320px] items-center gap-1.5">
                                <div className="min-w-0 flex-1">
                                    <Segmented
                                        label="Pamamalengke mode"
                                        value={mode}
                                        onChange={switchMode}
                                        options={[
                                            {
                                                value: 'plan',
                                                label: (
                                                    <>
                                                        <ListChecks className="size-4" />{' '}
                                                        Plan
                                                    </>
                                                ),
                                            },
                                            {
                                                value: 'shop',
                                                label: (
                                                    <>
                                                        <Check className="size-4" />{' '}
                                                        Shopping checklist
                                                    </>
                                                ),
                                            },
                                        ]}
                                    />
                                </div>
                                <button
                                    type="button"
                                    className={opsButtonClass}
                                    onClick={() => setSummaryOpen(true)}
                                >
                                    <ChartColumn className="size-4" />
                                    <span className="hidden sm:inline">
                                        View summary
                                    </span>
                                    <span className="sm:hidden">Summary</span>
                                </button>
                            </div>
                        </div>
                        <div className="grid grid-cols-2 gap-2 min-[640px]:grid-cols-4">
                            {(
                                [
                                    [
                                        'Auto recommended',
                                        autoOn.length,
                                        'bg-[#c8962e]',
                                    ],
                                    [
                                        'Manual items',
                                        manual.length,
                                        'bg-[#111]',
                                    ],
                                    [
                                        'Below target · not yet',
                                        market.hold,
                                        'bg-[#949494]',
                                    ],
                                    [
                                        'Needs setup',
                                        setup.length,
                                        'bg-[#b45309]',
                                    ],
                                ] as const
                            ).map(([label, value, dot]) => (
                                <div
                                    key={label}
                                    className="flex flex-col gap-1 rounded-xl bg-[#fafafa] px-3 py-2.5"
                                >
                                    <span className="flex min-w-0 items-center gap-1.5">
                                        <span
                                            className={`size-2 shrink-0 rounded-full ${dot}`}
                                            aria-hidden="true"
                                        />
                                        <span className="truncate text-[10.5px] font-semibold tracking-[0.05em] text-[#767676] uppercase">
                                            {label}
                                        </span>
                                    </span>
                                    <span className="text-lg font-bold tabular-nums">
                                        {value}
                                    </span>
                                </div>
                            ))}
                        </div>
                        <p className="text-[11.5px] leading-5 text-[#666]">
                            Target is the stock level you want to keep. A buy is
                            suggested only when an ingredient's rule is reached,
                            then rounded up to whole purchase units. Stock
                            itself is never rounded.
                        </p>
                    </section>

                    {mode === 'plan' ? (
                        <div className="flex flex-col gap-4">
                            <section
                                className="flex flex-col gap-2"
                                aria-labelledby="auto-heading"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-1.5">
                                    <h2
                                        id="auto-heading"
                                        className="flex items-center gap-2 text-sm font-bold"
                                    >
                                        <span
                                            className="size-[9px] rounded-full bg-[#c8962e]"
                                            aria-hidden="true"
                                        />{' '}
                                        Auto recommended
                                        <span className="inline-flex h-5 min-w-[22px] items-center justify-center rounded-full bg-[#f2f2f2] px-1.5 text-[11px] tabular-nums">
                                            {auto.length}
                                        </span>
                                    </h2>
                                    <span className="text-[11.5px] text-[#767676]">
                                        From current stock, target, purchase
                                        unit and rule.
                                    </span>
                                </div>
                                {auto.length === 0 ? (
                                    <div className="flex items-center gap-2.5 rounded-xl border border-[#e5e5e5] bg-white p-3">
                                        <span className="flex size-9 shrink-0 items-center justify-center rounded-full bg-emerald-50 text-emerald-700">
                                            <Check className="size-5" />
                                        </span>
                                        <span className="flex min-w-0 flex-col">
                                            <span className="text-[13.5px] font-semibold">
                                                No ingredient needs buying right
                                                now.
                                            </span>
                                            <span className="text-xs leading-5 text-[#767676]">
                                                Every ingredient is above its
                                                rule. Manual items below still
                                                go on the run.
                                            </span>
                                        </span>
                                    </div>
                                ) : (
                                    <div className="grid grid-cols-1 gap-2 min-[560px]:grid-cols-2 min-[1200px]:grid-cols-3">
                                        {auto.map(({ ingredient, skipped }) => {
                                            const recommendation =
                                                ingredient.recommendation!;
                                            const pu =
                                                ingredient.purchase_unit!;
                                            const same =
                                                pu.size === '1' &&
                                                pu.name ===
                                                    ingredient.base_unit;
                                            const others =
                                                ingredient.plan_ids.filter(
                                                    (id) => id !== plan.id,
                                                );

                                            return (
                                                <article
                                                    key={ingredient.id}
                                                    className={`flex min-w-0 flex-col gap-2.5 rounded-[14px] bg-white p-3 ${skipped ? 'border border-dashed border-[#c9c9c9] opacity-60' : 'border border-[#e5e5e5]'}`}
                                                >
                                                    <div className="flex items-start gap-2.5">
                                                        <IngredientIcon
                                                            icon={
                                                                ingredient.icon
                                                            }
                                                            size={38}
                                                        />
                                                        <span className="flex min-w-0 flex-1 flex-col">
                                                            <span className="text-sm leading-snug font-semibold wrap-anywhere">
                                                                {
                                                                    ingredient.name
                                                                }
                                                            </span>
                                                            <span className="text-xs text-[#666] tabular-nums">
                                                                Current{' '}
                                                                {formatQuantity(
                                                                    ingredient
                                                                        .stock!
                                                                        .current,
                                                                    ingredient.base_unit,
                                                                )}{' '}
                                                                · target{' '}
                                                                {formatQuantity(
                                                                    ingredient.target,
                                                                    ingredient.base_unit,
                                                                )}
                                                            </span>
                                                        </span>
                                                        <Chip
                                                            tone={
                                                                skipped
                                                                    ? 'outline'
                                                                    : 'gold'
                                                            }
                                                        >
                                                            {skipped
                                                                ? 'Skipped'
                                                                : 'Auto'}
                                                        </Chip>
                                                    </div>
                                                    <StockBar
                                                        ingredient={ingredient}
                                                    />
                                                    <span className="text-xs leading-5 text-[#444]">
                                                        {recommendation.reason}
                                                    </span>
                                                    <span className="text-[11px] leading-4 text-[#767676]">
                                                        Buys in{' '}
                                                        {purchaseUnitLabel(
                                                            ingredient,
                                                        )}
                                                        {pu.cost_cents !== null
                                                            ? ` · ${formatPeso(pu.cost_cents)}`
                                                            : ''}{' '}
                                                        ·{' '}
                                                        {ingredient.rule_label.toLowerCase()}
                                                    </span>
                                                    {others.length > 0 && (
                                                        <span className="text-[11px] leading-4 font-semibold text-[#7a5710]">
                                                            Shared with{' '}
                                                            {others
                                                                .map(
                                                                    (id) =>
                                                                        operations.plans.find(
                                                                            (
                                                                                item,
                                                                            ) =>
                                                                                item.id ===
                                                                                id,
                                                                        )?.name,
                                                                )
                                                                .join(', ')}
                                                            . Buying it here
                                                            restocks the one
                                                            branch record.
                                                        </span>
                                                    )}
                                                    <div className="flex items-end justify-between gap-2.5 border-t border-[#f2f2f2] pt-2.5">
                                                        <span className="flex min-w-0 flex-col gap-0.5">
                                                            <span
                                                                className={
                                                                    opsLabelClass
                                                                }
                                                            >
                                                                Recommended
                                                            </span>
                                                            <span className="text-lg font-bold tabular-nums">
                                                                {
                                                                    recommendation.units
                                                                }{' '}
                                                                {unitLabel(
                                                                    pu.name,
                                                                    recommendation.units,
                                                                )}
                                                            </span>
                                                            <span className="text-[11px] text-[#767676] tabular-nums">
                                                                {same
                                                                    ? ''
                                                                    : `= ${formatQuantity(recommendation.base_quantity, ingredient.base_unit)} · `}
                                                                after:{' '}
                                                                {formatQuantity(
                                                                    recommendation.after ??
                                                                        '0',
                                                                    ingredient.base_unit,
                                                                )}
                                                            </span>
                                                        </span>
                                                        <span className="flex shrink-0 flex-col items-end gap-1.5">
                                                            <span
                                                                className={`text-sm font-bold tabular-nums ${recommendation.estimate_cents === null ? 'text-[#b45309]' : ''}`}
                                                            >
                                                                {recommendation.estimate_cents ===
                                                                null
                                                                    ? 'Cost unknown'
                                                                    : formatPeso(
                                                                          recommendation.estimate_cents,
                                                                      )}
                                                            </span>
                                                            <button
                                                                type="button"
                                                                className={
                                                                    opsButtonClass
                                                                }
                                                                onClick={() =>
                                                                    router.put(
                                                                        operationsRoutes.pamamalengke.skip.url(
                                                                            {
                                                                                plan: plan.id,
                                                                                ingredient:
                                                                                    ingredient.id,
                                                                            },
                                                                        ),
                                                                        {
                                                                            skipped:
                                                                                !skipped,
                                                                        },
                                                                        {
                                                                            preserveScroll: true,
                                                                        },
                                                                    )
                                                                }
                                                            >
                                                                {skipped
                                                                    ? 'Restore'
                                                                    : 'Skip this run'}
                                                            </button>
                                                        </span>
                                                    </div>
                                                </article>
                                            );
                                        })}
                                    </div>
                                )}
                            </section>

                            {setup.length > 0 && (
                                <section
                                    className="flex flex-col gap-2"
                                    aria-labelledby="setup-heading"
                                >
                                    <h2
                                        id="setup-heading"
                                        className="flex items-center gap-2 text-sm font-bold"
                                    >
                                        <span
                                            className="size-[9px] rounded-full bg-[#b45309]"
                                            aria-hidden="true"
                                        />{' '}
                                        Needs setup
                                    </h2>
                                    {setup.map((ingredient) => (
                                        <div
                                            key={ingredient.id}
                                            className="flex flex-wrap items-center gap-2.5 rounded-xl border border-[#ebd3a3] bg-white p-3"
                                        >
                                            <IngredientIcon
                                                icon={ingredient.icon}
                                                size={38}
                                            />
                                            <span className="flex min-w-0 flex-[1_1_200px] flex-col">
                                                <span className="text-[13.5px] font-semibold">
                                                    {ingredient.name}
                                                </span>
                                                <span className="text-xs leading-5 text-[#767676]">
                                                    {formatQuantity(
                                                        ingredient.stock!
                                                            .current,
                                                        ingredient.base_unit,
                                                    )}{' '}
                                                    of{' '}
                                                    {formatQuantity(
                                                        ingredient.target,
                                                        ingredient.base_unit,
                                                    )}
                                                    .{' '}
                                                    {
                                                        ingredient
                                                            .recommendation
                                                            ?.reason
                                                    }
                                                </span>
                                            </span>
                                            <button
                                                type="button"
                                                className={opsButtonClass}
                                                onClick={() =>
                                                    setEditing(ingredient)
                                                }
                                            >
                                                <Pencil className="size-4" />{' '}
                                                Set purchase rule
                                            </button>
                                        </div>
                                    ))}
                                </section>
                            )}

                            <section
                                className="flex flex-col gap-2"
                                aria-labelledby="manual-heading"
                            >
                                <div className="flex flex-wrap items-center justify-between gap-2">
                                    <h2
                                        id="manual-heading"
                                        className="flex items-center gap-2 text-sm font-bold"
                                    >
                                        <span
                                            className="size-[9px] rounded-full bg-[#111]"
                                            aria-hidden="true"
                                        />{' '}
                                        Manually added
                                        <span className="inline-flex h-5 min-w-[22px] items-center justify-center rounded-full bg-[#f2f2f2] px-1.5 text-[11px] tabular-nums">
                                            {manual.length}
                                        </span>
                                    </h2>
                                    <button
                                        type="button"
                                        className={opsButtonClass}
                                        onClick={() => setManualOpen(true)}
                                    >
                                        <Plus className="size-4" /> Add manual
                                        item
                                    </button>
                                </div>
                                {manual.length === 0 ? (
                                    <p className="rounded-xl border border-dashed border-[#d8d8d8] bg-white p-3 text-[12.5px] leading-5 text-[#767676]">
                                        No manual items. Add ice, tissue, gas or
                                        anything else this run needs. Manual
                                        items are not linked to ingredient
                                        stock.
                                    </p>
                                ) : (
                                    <ul className="overflow-hidden rounded-xl border border-[#e5e5e5] bg-white">
                                        {manual.map((entry) => (
                                            <li
                                                key={entry.id}
                                                className="flex items-center gap-2.5 border-b border-[#f2f2f2] px-3 py-2.5 last:border-b-0"
                                            >
                                                <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#f7f7f7]">
                                                    <ShoppingCart className="size-4" />
                                                </span>
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="text-[13.5px] font-semibold wrap-anywhere">
                                                        {entry.name}
                                                    </span>
                                                    <span className="text-[11.5px] text-[#767676]">
                                                        {formatQuantity(
                                                            entry.quantity,
                                                            entry.unit,
                                                        )}{' '}
                                                        ·{' '}
                                                        {entry.estimated_unit_cost_cents ===
                                                        null
                                                            ? 'cost unknown'
                                                            : `${formatPeso(entry.estimated_unit_cost_cents)} each`}
                                                        {entry.note
                                                            ? ` · ${entry.note}`
                                                            : ''}{' '}
                                                        · not linked to stock
                                                    </span>
                                                </span>
                                                <span
                                                    className={`text-[13px] font-semibold whitespace-nowrap tabular-nums ${entry.estimate_cents === null ? 'text-[#b45309]' : ''}`}
                                                >
                                                    {entry.estimate_cents ===
                                                    null
                                                        ? 'Cost unknown'
                                                        : formatPeso(
                                                              entry.estimate_cents,
                                                          )}
                                                </span>
                                                <button
                                                    type="button"
                                                    aria-label={`Remove ${entry.name}`}
                                                    className="flex size-11 shrink-0 items-center justify-center rounded-[10px] border border-[#e5e5e5] text-[#767676]"
                                                    onClick={() =>
                                                        router.delete(
                                                            operationsRoutes.pamamalengke.manual.destroy.url(
                                                                entry.id,
                                                            ),
                                                            {
                                                                preserveScroll: true,
                                                            },
                                                        )
                                                    }
                                                >
                                                    <Trash2 className="size-4" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>

                            <section className="overflow-hidden rounded-xl border border-[#e5e5e5] bg-white">
                                <button
                                    type="button"
                                    aria-expanded={showOk}
                                    onClick={() =>
                                        setShowOk((current) => !current)
                                    }
                                    className="flex min-h-14 w-full items-center justify-between gap-3 px-3.5 py-2.5 text-left"
                                >
                                    <span className="flex min-w-0 flex-col">
                                        <span className="text-[13.5px] font-bold">
                                            No purchase needed
                                        </span>
                                        <span className="text-xs leading-5 text-[#767676]">
                                            {ok.length} ingredient
                                            {ok.length === 1 ? '' : 's'}
                                            {market.hold
                                                ? `, including ${market.hold} below target whose rule isn't reached yet.`
                                                : ' at or above target, or not suggested automatically.'}
                                        </span>
                                    </span>
                                    <ChevronDown
                                        className={`size-4 shrink-0 text-[#767676] transition ${showOk ? 'rotate-180' : ''}`}
                                    />
                                </button>
                                {showOk && (
                                    <ul>
                                        {ok.map((ingredient) => (
                                            <li
                                                key={ingredient.id}
                                                className="flex items-start gap-2.5 border-t border-[#f2f2f2] px-3.5 py-2.5"
                                            >
                                                <IngredientIcon
                                                    icon={ingredient.icon}
                                                    size={34}
                                                />
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="flex flex-wrap items-center gap-x-2.5 gap-y-1">
                                                        <span className="text-[13.5px] font-semibold">
                                                            {ingredient.name}
                                                        </span>
                                                        <span className="text-xs text-[#666] tabular-nums">
                                                            {formatQuantity(
                                                                ingredient
                                                                    .stock!
                                                                    .current,
                                                                ingredient.base_unit,
                                                            )}{' '}
                                                            /{' '}
                                                            {formatQuantity(
                                                                ingredient.target,
                                                                ingredient.base_unit,
                                                            )}
                                                        </span>
                                                    </span>
                                                    <span className="text-xs leading-5 text-[#767676]">
                                                        {
                                                            ingredient
                                                                .recommendation
                                                                ?.reason
                                                        }
                                                    </span>
                                                </span>
                                                <StatusChip
                                                    ingredient={ingredient}
                                                />
                                            </li>
                                        ))}
                                    </ul>
                                )}
                            </section>
                        </div>
                    ) : (
                        <div className="flex flex-col gap-2.5 pb-24 md:pb-0">
                            {items.length === 0 ? (
                                <EmptyState
                                    title="The list is empty."
                                    body="No ingredient has reached its rule and nothing was added by hand."
                                    action={
                                        <button
                                            type="button"
                                            className={opsButtonClass}
                                            onClick={() => setManualOpen(true)}
                                        >
                                            <Plus className="size-4" /> Add
                                            manual item
                                        </button>
                                    }
                                />
                            ) : (
                                <>
                                    <ul className="overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white">
                                        {items.map((item) => (
                                            <ChecklistRow
                                                key={item.key}
                                                item={item}
                                                line={
                                                    checklist.lines[item.key] ??
                                                    defaultLine(item)
                                                }
                                                ingredient={
                                                    item.ingredientId
                                                        ? byId.get(
                                                              item.ingredientId,
                                                          )
                                                        : undefined
                                                }
                                                onChange={(change) =>
                                                    patch(item, change)
                                                }
                                            />
                                        ))}
                                    </ul>
                                    <p className="text-[11.5px] leading-5 text-[#767676]">
                                        Tap the box when an item is in the bag.
                                        Tap its name to change quantity or
                                        price, or mark it not available.
                                        Progress is kept on this device until
                                        you confirm.
                                    </p>
                                </>
                            )}
                            <div className="fixed right-3 bottom-[calc(88px+env(safe-area-inset-bottom,0px))] left-3 z-30 mx-auto flex max-w-[430px] items-center gap-3 rounded-2xl border border-[#111] bg-white py-2.5 pr-2.5 pl-3.5 shadow-[0_10px_28px_rgba(0,0,0,0.14)] md:sticky md:bottom-2 md:max-w-none">
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="text-[13.5px] font-bold">
                                        {bought.length} of {items.length} bought
                                        {unavailable
                                            ? ` · ${unavailable} not available`
                                            : ''}
                                    </span>
                                    <span className="text-[11.5px] text-[#666] tabular-nums">
                                        Actual {formatPeso(actual)} · Estimated{' '}
                                        {formatPeso(estimate)}
                                    </span>
                                </span>
                                <button
                                    type="button"
                                    className={opsPrimaryClass}
                                    disabled={blocked !== null}
                                    title={
                                        blocked ??
                                        'Review and confirm this purchase'
                                    }
                                    onClick={() => setReviewOpen(true)}
                                >
                                    <Check className="size-4" /> Review purchase
                                </button>
                            </div>
                        </div>
                    )}
                </>
            )}

            {summaryOpen && (
                <SummaryDialog
                    open
                    onClose={() => setSummaryOpen(false)}
                    planName={plan.name}
                    summary={props.summary}
                    auto={
                        branch
                            ? autoOn.map(({ ingredient }) => ({
                                  name: ingredient.name,
                                  quantity: `${ingredient.recommendation?.units} ${unitLabel(ingredient.purchase_unit?.name ?? '', ingredient.recommendation?.units ?? 0)}`,
                                  estimate_cents:
                                      ingredient.recommendation
                                          ?.estimate_cents ?? null,
                              }))
                            : null
                    }
                    manual={manual}
                    earlier={props.earlier}
                    shopping={{
                        estimate_cents: estimateBought,
                        actual_cents: actual,
                        checked: bought.length,
                        total: items.length,
                    }}
                />
            )}
            {manualOpen && (
                <ManualItemDialog
                    planId={plan.id}
                    planName={plan.name}
                    onClose={() => setManualOpen(false)}
                />
            )}
            {editing && (
                <IngredientDialog
                    ingredient={editing}
                    operations={operations}
                    onClose={() => setEditing(null)}
                />
            )}
            {reviewOpen && branch && (
                <ConfirmDialog
                    planId={plan.id}
                    planName={plan.name}
                    branchName={branch.name}
                    hasOpenSession={operations.has_open_store_session === true}
                    bought={bought}
                    left={items.filter(
                        (item) => !bought.some((line) => line.key === item.key),
                    )}
                    checklist={checklist}
                    lines={checklist.lines}
                    byId={byId}
                    onPaymentSource={(source) =>
                        setChecklist((current) => ({
                            ...current,
                            paymentSource: source,
                        }))
                    }
                    onKey={(key) =>
                        setChecklist((current) => ({
                            ...current,
                            idempotencyKey: key,
                        }))
                    }
                    onClose={() => setReviewOpen(false)}
                />
            )}
        </OperationsShell>
    );
}

function ChecklistRow({
    item,
    line,
    ingredient,
    onChange,
}: {
    item: ChecklistItem;
    line: ChecklistLine;
    ingredient?: OperationsIngredient;
    onChange: (change: Partial<ChecklistLine>) => void;
}) {
    const total = lineTotalCents(line.quantity, line.unitCost);
    const quantity = parseQuantity(line.quantity);
    const planned = parseQuantity(item.planned);
    const bought = line.bought && !line.unavailable;
    const step = (direction: 1 | -1) => {
        const next = Math.max(0, (quantity ?? 0) + direction * 10_000);
        onChange({
            quantity: formatScaled(next).replaceAll(',', ''),
            ...(direction === 1 ? { bought: true, unavailable: false } : {}),
        });
    };

    return (
        <li
            className={`border-b border-[#f2f2f2] last:border-b-0 ${bought ? 'bg-[#fafafa]' : 'bg-white'}`}
        >
            <div className="flex items-center gap-2.5 px-3 py-2.5">
                <button
                    type="button"
                    role="checkbox"
                    aria-checked={bought}
                    aria-label={
                        bought
                            ? `Mark ${item.name} as not bought`
                            : `Mark ${item.name} as bought`
                    }
                    onClick={() =>
                        onChange({ bought: !bought, unavailable: false })
                    }
                    className={`flex size-11 shrink-0 items-center justify-center rounded-xl ${bought ? 'border border-[#111] bg-[#111] text-white' : line.unavailable ? 'border border-dashed border-[#c9c9c9] bg-[#f2f2f2] text-[#949494]' : 'border-[1.5px] border-[#b5b5b5] bg-white'}`}
                >
                    {bought && <Check className="size-5" strokeWidth={2.6} />}
                </button>
                <button
                    type="button"
                    aria-expanded={line.open}
                    onClick={() => onChange({ open: !line.open })}
                    className="flex min-h-11 min-w-0 flex-1 flex-col items-start justify-center text-left"
                >
                    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
                        <span
                            className={`text-sm leading-snug font-semibold wrap-anywhere ${bought ? 'text-[#666]' : ''}`}
                        >
                            {item.name}
                        </span>
                        <Chip
                            tone={item.type === 'ingredient' ? 'gold' : 'plain'}
                        >
                            {item.type === 'ingredient' ? 'Auto' : 'Manual'}
                        </Chip>
                    </span>
                    <span className="text-xs text-[#767676] tabular-nums">
                        {formatQuantity(item.planned, item.unit)} ·{' '}
                        {item.estimatedUnitCents === null
                            ? 'cost unknown'
                            : `≈ ${formatPeso(lineTotalCents(item.planned, (item.estimatedUnitCents / 100).toFixed(2)) ?? 0)}`}
                    </span>
                </button>
                <span className="flex shrink-0 flex-col items-end">
                    <span
                        className={`text-[13.5px] font-bold tabular-nums ${bought ? (total === null ? 'text-[#b45309]' : '') : line.unavailable ? 'text-xs font-semibold text-[#949494]' : 'text-[#c9c9c9]'}`}
                    >
                        {bought
                            ? total === null
                                ? 'Cost?'
                                : formatPeso(total)
                            : line.unavailable
                              ? 'Not available'
                              : '—'}
                    </span>
                    {bought && (
                        <span className="text-[11px] text-[#767676] tabular-nums">
                            {formatQuantity(line.quantity || '0', item.unit)}
                        </span>
                    )}
                </span>
            </div>
            {line.open && (
                <div className="flex flex-col gap-2.5 px-3 pb-3">
                    <div className="grid grid-cols-2 gap-2.5 min-[700px]:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)_minmax(0,1fr)]">
                        <div className="col-span-2 flex min-w-0 flex-col gap-1.5 min-[700px]:col-span-1">
                            <span className={opsLabelClass}>
                                Actual quantity ({unitLabel(item.unit, 2)})
                            </span>
                            <div className="flex gap-1.5">
                                <button
                                    type="button"
                                    aria-label={`Less ${item.name}`}
                                    className={`${opsButtonClass} w-11 shrink-0 px-0`}
                                    onClick={() => step(-1)}
                                >
                                    <Minus className="size-4" />
                                </button>
                                <input
                                    aria-label={`Actual quantity of ${item.name}`}
                                    inputMode="decimal"
                                    className={`${opsInputClass} text-center tabular-nums`}
                                    value={line.quantity}
                                    onChange={(event) =>
                                        onChange({
                                            quantity: event.target.value,
                                        })
                                    }
                                />
                                <button
                                    type="button"
                                    aria-label={`More ${item.name}`}
                                    className={`${opsButtonClass} w-11 shrink-0 px-0`}
                                    onClick={() => step(1)}
                                >
                                    <Plus className="size-4" />
                                </button>
                            </div>
                        </div>
                        <label className="flex min-w-0 flex-col gap-1.5">
                            <span className={opsLabelClass}>
                                Unit cost (₱ per {item.unit})
                            </span>
                            <input
                                className={`${opsInputClass} tabular-nums`}
                                inputMode="decimal"
                                placeholder="0.00"
                                value={line.unitCost}
                                onChange={(event) =>
                                    onChange({ unitCost: event.target.value })
                                }
                            />
                        </label>
                        <div className="flex min-w-0 flex-col gap-1.5">
                            <span className={opsLabelClass}>Actual total</span>
                            <span className="flex h-11 items-center text-base font-bold tabular-nums">
                                {total === null ? '—' : formatPeso(total)}
                            </span>
                        </div>
                    </div>
                    <input
                        className={opsInputClass}
                        aria-label={`Note for ${item.name}`}
                        placeholder="Note: stall, brand or substitute (optional)"
                        maxLength={150}
                        value={line.note}
                        onChange={(event) =>
                            onChange({ note: event.target.value })
                        }
                    />
                    <div className="flex flex-wrap items-center justify-between gap-2">
                        <span className="flex-[1_1_200px] text-[11.5px] leading-5 text-[#666]">
                            {item.type === 'ingredient' &&
                            ingredient?.purchase_unit
                                ? `${quantity !== null && planned !== null && quantity !== planned ? `Recommended ${formatQuantity(item.planned, item.unit)}, buying ${formatQuantity(line.quantity || '0', item.unit)}. ` : ''}Adds ${quantity === null ? '—' : formatQuantity(formatScaled(Math.round((quantity * (parseQuantity(ingredient.purchase_unit.size) ?? 0)) / 10_000)).replaceAll(',', ''), ingredient.base_unit)} to ${ingredient.name} stock.`
                                : 'Not linked to ingredient stock.'}
                        </span>
                        <button
                            type="button"
                            aria-pressed={line.unavailable}
                            onClick={() =>
                                onChange({
                                    unavailable: !line.unavailable,
                                    bought: false,
                                })
                            }
                            className={`inline-flex min-h-11 items-center rounded-[10px] border px-3 text-[12.5px] font-semibold ${line.unavailable ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white'}`}
                        >
                            {line.unavailable
                                ? 'Available again'
                                : 'Not available'}
                        </button>
                    </div>
                </div>
            )}
        </li>
    );
}

const QUICK_ITEMS: [string, string, string][] = [
    ['Ice', 'bag', ''],
    ['Tissue', 'pack', ''],
    ['Dishwashing liquid', 'pouch', ''],
    ['Cleaning supplies', 'pc', ''],
    ['Gas / LPG', 'tank', ''],
    ['Other', 'pc', ''],
];

function ManualItemDialog({
    planId,
    planName,
    onClose,
}: {
    planId: string;
    planName: string;
    onClose: () => void;
}) {
    const [name, setName] = useState('');
    const [quantity, setQuantity] = useState('1');
    const [unit, setUnit] = useState('pc');
    const [cost, setCost] = useState('');
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const save = () => {
        setBusy(true);
        router.post(
            operationsRoutes.pamamalengke.manual.store.url(planId),
            {
                name,
                quantity,
                unit,
                estimated_unit_cost: cost || null,
                note: note || null,
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (next) => setErrors(next),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <OperationsDialog
            open
            busy={busy}
            onClose={onClose}
            kicker={`${planName} plan · Pamamalengke`}
            title="Add manual item"
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
                            <Plus className="size-4" /> Add to list
                        </button>
                    </div>
                </div>
            }
        >
            <div className="flex flex-col gap-4">
                <div className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Common items</span>
                    <div className="flex flex-wrap gap-1.5">
                        {QUICK_ITEMS.map(([label, itemUnit]) => (
                            <button
                                key={label}
                                type="button"
                                aria-pressed={name === label}
                                onClick={() => {
                                    setName(label === 'Other' ? '' : label);
                                    setUnit(itemUnit);
                                }}
                                className={`inline-flex min-h-10 items-center rounded-[10px] border px-3 text-[12.5px] font-semibold ${name === label ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white'}`}
                            >
                                {label}
                            </button>
                        ))}
                    </div>
                </div>
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Item</span>
                    <input
                        className={opsInputClass}
                        value={name}
                        maxLength={80}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Ice"
                    />
                </label>
                <div className="grid grid-cols-3 gap-2.5">
                    <label className="flex min-w-0 flex-col gap-1.5">
                        <span className={opsLabelClass}>Quantity</span>
                        <input
                            className={opsInputClass}
                            inputMode="decimal"
                            value={quantity}
                            onChange={(event) =>
                                setQuantity(event.target.value)
                            }
                        />
                    </label>
                    <label className="flex min-w-0 flex-col gap-1.5">
                        <span className={opsLabelClass}>Unit</span>
                        <input
                            className={opsInputClass}
                            value={unit}
                            maxLength={30}
                            onChange={(event) => setUnit(event.target.value)}
                            placeholder="bag"
                        />
                    </label>
                    <label className="flex min-w-0 flex-col gap-1.5">
                        <span className={opsLabelClass}>Est. each (₱)</span>
                        <input
                            className={opsInputClass}
                            inputMode="decimal"
                            value={cost}
                            onChange={(event) => setCost(event.target.value)}
                            placeholder="Unknown"
                        />
                    </label>
                </div>
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Note (optional)</span>
                    <input
                        className={opsInputClass}
                        value={note}
                        maxLength={150}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="Stall or brand"
                    />
                </label>
                <p className="text-[11.5px] leading-5 text-[#767676]">
                    Manual items aren't linked to ingredient stock. When you buy
                    them, they're saved in the same Store Purchase as the rest
                    of the run.
                </p>
            </div>
        </OperationsDialog>
    );
}

function ConfirmDialog({
    planId,
    planName,
    branchName,
    hasOpenSession,
    bought,
    left,
    checklist,
    lines,
    byId,
    onPaymentSource,
    onKey,
    onClose,
}: {
    planId: string;
    planName: string;
    branchName: string;
    hasOpenSession: boolean;
    bought: ReturnType<typeof boughtLines>;
    left: ChecklistItem[];
    checklist: ChecklistState;
    lines: Record<string, ChecklistLine>;
    byId: Map<string, OperationsIngredient>;
    onPaymentSource: (source: 'cash' | 'cashless') => void;
    onKey: (key: string) => void;
    onClose: () => void;
}) {
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const missingCost = bought.filter((item) => item.totalCents === null);
    const total = bought.reduce((sum, item) => sum + (item.totalCents ?? 0), 0);
    const estimate = bought.reduce(
        (sum, item) => sum + (item.estimateCents ?? 0),
        0,
    );
    const linked = bought.filter((item) => item.type === 'ingredient');
    const priceChanges = linked.filter((item) => {
        const ingredient = byId.get(item.ingredientId!);

        return (
            ingredient?.purchase_unit &&
            ingredient.purchase_unit.cost_cents !==
                parseMoney(item.line.unitCost)
        );
    });

    const confirm = () => {
        if (!hasOpenSession) {
            setErrors({
                store: `Open the Store at ${branchName} first. A pamamalengke purchase is saved as a Store Purchase of the open Store Session.`,
            });

            return;
        }
        if (missingCost.length) {
            setErrors({
                items: `Enter the unit cost for ${missingCost.map((item) => item.name).join(', ')}.`,
            });

            return;
        }
        const key = checklist.idempotencyKey ?? createClientUuid();
        onKey(key);
        setBusy(true);
        router.post(
            operationsRoutes.pamamalengke.confirm.url(planId),
            {
                idempotency_key: key,
                payment_source: checklist.paymentSource,
                items: bought.map((item) => ({
                    type: item.type,
                    ingredient_id: item.ingredientId,
                    entry_id: item.entryId,
                    name: item.type === 'manual' ? item.name : null,
                    unit: item.type === 'manual' ? item.unit : null,
                    actual_quantity: lines[item.key]?.quantity ?? item.planned,
                    actual_unit_cost: lines[item.key]?.unitCost ?? '',
                    note: lines[item.key]?.note || null,
                })),
            },
            {
                preserveScroll: true,
                onSuccess: () => onClose(),
                onError: (next) => setErrors(next),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <OperationsDialog
            open
            busy={busy}
            onClose={onClose}
            kicker={`${planName} plan · Confirm pamamalengke`}
            title={`${bought.length} item${bought.length === 1 ? '' : 's'} · ${formatPeso(total)}`}
            footer={
                <div className="flex flex-col gap-2">
                    {Object.values(errors).length > 0 && (
                        <p
                            role="alert"
                            className="flex items-start gap-1.5 text-xs font-semibold text-[#b91c1c]"
                        >
                            <TriangleAlert className="mt-px size-4 shrink-0" />{' '}
                            {Object.values(errors)[0]}
                        </p>
                    )}
                    <div className="flex gap-2">
                        <button
                            type="button"
                            className={`${opsButtonClass} flex-1`}
                            onClick={onClose}
                            disabled={busy}
                        >
                            Back to list
                        </button>
                        <button
                            type="button"
                            className={`${opsPrimaryClass} flex-1`}
                            onClick={confirm}
                            disabled={busy || !hasOpenSession}
                        >
                            <Check className="size-4" /> Confirm pamamalengke
                        </button>
                    </div>
                </div>
            }
        >
            <div className="flex flex-col gap-4">
                {!hasOpenSession && (
                    <p
                        role="status"
                        className="rounded-xl border border-amber-200 bg-amber-50 p-3 text-xs leading-5 text-amber-900"
                    >
                        The Store at {branchName} is closed. A confirmed
                        pamamalengke is saved as a Store Purchase of the open
                        Store Session, so a Cashier must open the Store first.
                        Your checklist stays on this device.
                    </p>
                )}
                <ul className="overflow-hidden rounded-xl border border-[#e5e5e5]">
                    {bought.map((item) => (
                        <li
                            key={item.key}
                            className="flex items-start gap-3 border-b border-[#f2f2f2] px-3.5 py-3 last:border-b-0"
                        >
                            <span className="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span className="flex flex-wrap items-center gap-1.5">
                                    <span className="text-[13.5px] font-semibold wrap-anywhere">
                                        {item.name}
                                    </span>
                                    <Chip
                                        tone={
                                            item.type === 'ingredient'
                                                ? 'gold'
                                                : 'plain'
                                        }
                                    >
                                        {item.type === 'ingredient'
                                            ? 'Auto'
                                            : 'Manual'}
                                    </Chip>
                                </span>
                                <span className="text-xs leading-5 text-[#666] tabular-nums">
                                    Recommended{' '}
                                    {formatQuantity(item.planned, item.unit)} ·
                                    Actual{' '}
                                    {formatQuantity(
                                        item.line.quantity,
                                        item.unit,
                                    )}
                                    {item.line.note
                                        ? ` · ${item.line.note}`
                                        : ''}
                                </span>
                            </span>
                            <span className="flex shrink-0 flex-col items-end">
                                <span
                                    className={`text-[13.5px] font-bold tabular-nums ${item.totalCents === null ? 'text-[#b91c1c]' : ''}`}
                                >
                                    {item.totalCents === null
                                        ? 'Cost needed'
                                        : formatPeso(item.totalCents)}
                                </span>
                                {item.totalCents !== null && (
                                    <span className="text-[11px] text-[#767676] tabular-nums">
                                        {item.line.quantity} × ₱
                                        {item.line.unitCost}
                                    </span>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
                <div className="flex items-center gap-3 rounded-xl bg-[#f7f7f7] p-3">
                    <span className="flex flex-1 flex-col">
                        <span className="text-[13px] font-bold">
                            Actual total paid
                        </span>
                        <span className="text-[11px] text-[#767676] tabular-nums">
                            Estimated {formatPeso(estimate)} for these items
                        </span>
                    </span>
                    <span className="text-lg font-bold tabular-nums">
                        {formatPeso(total)}
                    </span>
                </div>
                {left.length > 0 && (
                    <p className="text-xs leading-5 text-[#666]">
                        Left on the list:{' '}
                        {left
                            .map(
                                (item) =>
                                    `${item.name}${lines[item.key]?.unavailable ? ' (not available)' : ''}`,
                            )
                            .join(', ')}
                        . Manual items stay for the next run; automatic
                        suggestions are recalculated from stock.
                    </p>
                )}
                <div className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Paid from</span>
                    <Segmented
                        label="Paid from"
                        value={checklist.paymentSource}
                        onChange={onPaymentSource}
                        options={[
                            { value: 'cash', label: 'Cash drawer' },
                            { value: 'cashless', label: 'Cashless' },
                        ]}
                    />
                    <span className="text-[11.5px] leading-5 text-[#767676]">
                        A Store Purchase reduces the expected closing{' '}
                        {checklist.paymentSource === 'cash'
                            ? 'Cash'
                            : 'Cashless'}{' '}
                        of the open Store Session.
                    </span>
                </div>
                <div className="flex flex-col gap-2">
                    <span className={opsLabelClass}>When you confirm</span>
                    {[
                        [
                            Layers,
                            'Ingredient stock',
                            linked.length
                                ? linked
                                      .map((item) => {
                                          const ingredient = byId.get(
                                              item.ingredientId!,
                                          );
                                          const size =
                                              parseQuantity(
                                                  ingredient?.purchase_unit
                                                      ?.size ?? '0',
                                              ) ?? 0;
                                          const base = Math.round(
                                              ((parseQuantity(
                                                  item.line.quantity,
                                              ) ?? 0) *
                                                  size) /
                                                  10_000,
                                          );

                                          return `+${formatQuantity(formatScaled(base).replaceAll(',', ''), ingredient?.base_unit ?? '')} ${item.name}`;
                                      })
                                      .join(' · ')
                                : 'No ingredient stock changes. Manual items only.',
                        ],
                        [
                            ShoppingCart,
                            `Existing Store Purchase / Expense · ${formatPeso(total)}`,
                            'Saved once in the existing Store Session expenses. Pamamalengke keeps no ledger of its own.',
                        ],
                        [
                            ListChecks,
                            'Purchase history',
                            'Recorded with recommended and actual quantities side by side, and audited with your name and the time.',
                        ],
                        ...(priceChanges.length
                            ? [
                                  [
                                      ChartColumn,
                                      'Next estimates',
                                      `${priceChanges.map((item) => `${item.name} ₱${item.line.unitCost} per ${item.unit}`).join(' · ')}. Past sales keep their recorded costs.`,
                                  ] as const,
                              ]
                            : []),
                    ].map(([Icon, title, body]) => (
                        <div
                            key={title as string}
                            className="flex items-start gap-2.5 rounded-xl border border-[#efefef] p-2.5"
                        >
                            <span className="flex size-8 shrink-0 items-center justify-center rounded-lg bg-[#f7f7f7]">
                                <Icon className="size-4" />
                            </span>
                            <span className="flex min-w-0 flex-col gap-0.5">
                                <span className="text-[13px] font-semibold">
                                    {title as string}
                                </span>
                                <span className="text-xs leading-5 wrap-anywhere text-[#666]">
                                    {body as string}
                                </span>
                            </span>
                        </div>
                    ))}
                </div>
                <Link
                    href={operationsHref('purchases', planId)}
                    className="text-xs font-semibold underline"
                >
                    See past runs in Purchases
                </Link>
            </div>
        </OperationsDialog>
    );
}
