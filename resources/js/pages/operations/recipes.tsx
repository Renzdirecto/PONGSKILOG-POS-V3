import { Link, router } from '@inertiajs/react';
import { Box, Check, CircleAlert, CupSoda, Plus, Trash2 } from 'lucide-react';
import { useState } from 'react';
import {
    EmptyState,
    IngredientIcon,
    OperationsShell,
    opsButtonClass,
    opsCardClass,
    opsInputClass,
    opsLabelClass,
    opsPrimaryClass,
    operationsHref,
} from '@/components/operations-ui';
import {
    estimateLineCents,
    formatPeso,
    formatQuantity,
    parseQuantity,
} from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type {
    OperationsContext,
    OperationsIngredient,
    RecipeProduct,
} from '@/types/operations';

type Props = {
    operations: OperationsContext;
    selectedProductId: string | null;
    products: RecipeProduct[];
    ingredients: OperationsIngredient[];
};

type DraftRow = { ingredient_id: string; quantity: string };

const STATE_NOTE: Record<RecipeProduct['state'], [string, string]> = {
    set: ['Recipe set', 'text-[#15803d]'],
    partial: ['Some sizes missing', 'font-semibold text-[#b45309]'],
    missing: ['Missing recipe', 'font-semibold text-[#b45309]'],
    not_needed: ['No recipe needed', 'text-[#8a8a8a]'],
};

export default function OperationsRecipes({
    operations,
    selectedProductId,
    products,
    ingredients,
}: Props) {
    const plan = operations.plans.find(
        (item) => item.id === operations.active_plan_id,
    )!;
    const initial =
        products.find((product) => product.id === selectedProductId) ??
        products.find((product) => product.state === 'set') ??
        products[0];
    const [productId, setProductId] = useState(initial?.id ?? null);
    const product = products.find((item) => item.id === productId) ?? initial;
    const [sizeKey, setSizeKey] = useState<string | null>(null);
    const [draft, setDraft] = useState<{
        key: string;
        rows: DraftRow[];
    } | null>(null);
    const [adding, setAdding] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const byId = new Map(
        ingredients.map((ingredient) => [ingredient.id, ingredient]),
    );

    const pick = (id: string) => {
        setProductId(id);
        setSizeKey(null);
        setDraft(null);
        setErrors({});
        setAdding('');
    };

    if (!product) {
        return (
            <OperationsShell
                operations={operations}
                title="Recipes"
                description="What each existing product consumes from ingredient stock when it sells."
            >
                <EmptyState
                    title="This plan has no products yet."
                    body="Recipes attach to existing products from Catalog › Products. Add products to the plan from Pamalengke Plans."
                    action={
                        <Link
                            href={operationsHref('plans')}
                            className={opsButtonClass}
                        >
                            Open Pamalengke Plans
                        </Link>
                    }
                />
            </OperationsShell>
        );
    }

    const size =
        product.sizes.find((item) => item.key === sizeKey) ??
        product.sizes[Math.min(1, product.sizes.length - 1)];
    const draftKey = `${product.id}:${size.key}`;
    const saved: DraftRow[] = size.lines ?? [];
    const rows = draft?.key === draftKey ? draft.rows : saved;
    const editing = draft?.key === draftKey || saved.length > 0;
    const dirty =
        draft?.key === draftKey &&
        JSON.stringify(draft.rows) !== JSON.stringify(saved);
    const directReason = product.tracked_at.length
        ? `${product.name} deducts Product stock at ${product.tracked_at.join(', ')} (Catalog › Inventory), so its sales never consume ingredients.`
        : product.no_recipe_needed
          ? `${product.name} is resold as a whole item, so no ingredient stock moves.`
          : null;
    const costs = rows.map((row) =>
        estimateLineCents(
            row.quantity,
            byId.get(row.ingredient_id)?.purchase_unit ?? null,
        ),
    );
    const unknown = costs.some((cost) => cost === null);
    const total = costs.reduce<number>((sum, cost) => sum + (cost ?? 0), 0);
    const sizeLabel =
        product.sizes.length > 1
            ? `${size.name} ${product.name}`
            : product.name;
    const setRows = (next: DraftRow[]) => {
        setDraft({ key: draftKey, rows: next });
        setErrors({});
    };
    const available = ingredients
        .filter(
            (ingredient) =>
                !rows.some((row) => row.ingredient_id === ingredient.id),
        )
        .sort(
            (left, right) =>
                Number(right.plan_ids.includes(plan.id)) -
                Number(left.plan_ids.includes(plan.id)),
        );
    const other = product.sizes.find(
        (item) => item.key !== size.key && item.lines?.length,
    );

    const save = () => {
        const bad = rows.find((row) => !(parseQuantity(row.quantity) ?? 0));
        if (bad) {
            setErrors({
                lines: `Enter a quantity above zero for ${byId.get(bad.ingredient_id)?.name ?? 'each ingredient'}.`,
            });

            return;
        }
        setBusy(true);
        router.put(
            operationsRoutes.recipes.update.url(product.id),
            { size_option_id: size.option_id, lines: rows },
            {
                preserveScroll: true,
                onSuccess: () => setDraft(null),
                onError: (next) => setErrors(next),
                onFinish: () => setBusy(false),
            },
        );
    };
    const setMode = (noRecipeNeeded: boolean) => {
        setBusy(true);
        router.put(
            operationsRoutes.recipes.mode.url(product.id),
            { no_recipe_needed: noRecipeNeeded },
            {
                preserveScroll: true,
                onError: (next) => setErrors(next),
                onFinish: () => setBusy(false),
            },
        );
    };

    return (
        <OperationsShell
            operations={operations}
            title="Recipes"
            description="What each existing product consumes from ingredient stock when it sells. Products come from Catalog › Products."
        >
            <div className="grid grid-cols-1 gap-2 min-[700px]:grid-cols-3">
                {[
                    [
                        '1',
                        'Product',
                        'What the customer buys',
                        `${product.name}${product.sizes.length > 1 ? ` · ${product.sizes.map((item) => item.name).join(', ')}` : ''}. Lives in Catalog › Products.`,
                    ],
                    [
                        '2',
                        'Recipe',
                        'The bridge',
                        directReason
                            ? 'None needed. Sold as a whole item.'
                            : saved.length
                              ? `${sizeLabel}: ${saved
                                    .slice(0, 3)
                                    .map(
                                        (row) =>
                                            `${byId.get(row.ingredient_id)?.name ?? 'Ingredient'} ${formatQuantity(row.quantity, byId.get(row.ingredient_id)?.base_unit ?? '')}`,
                                    )
                                    .join(', ')}${saved.length > 3 ? '…' : ''}`
                              : `Not set for ${sizeLabel} yet.`,
                    ],
                    [
                        '3',
                        'Ingredient',
                        'What the sale consumes',
                        directReason
                            ? 'Product stock in Catalog › Inventory instead.'
                            : 'One branch stock record each, shared across plans.',
                    ],
                ].map(([n, kicker, title, body]) => (
                    <div
                        key={n}
                        className="flex items-start gap-2.5 rounded-xl border border-[#e5e5e5] bg-white p-3"
                    >
                        <span className="flex size-6 shrink-0 items-center justify-center rounded-full bg-[#111] text-[11px] font-bold text-white">
                            {n}
                        </span>
                        <span className="flex min-w-0 flex-col gap-0.5">
                            <span className="flex flex-wrap items-baseline gap-1.5">
                                <span className="text-[13px] font-bold">
                                    {kicker}
                                </span>
                                <span className="text-[11.5px] text-[#767676]">
                                    {title}
                                </span>
                            </span>
                            <span className="text-[11.5px] leading-snug wrap-anywhere text-[#444]">
                                {body}
                            </span>
                        </span>
                    </div>
                ))}
            </div>

            <div className="grid grid-cols-1 items-start gap-2.5 min-[820px]:grid-cols-[250px_minmax(0,1fr)] md:gap-3">
                <nav
                    aria-label="Products in this plan"
                    className="hidden flex-col gap-1 rounded-2xl border border-[#e5e5e5] bg-white p-2 min-[820px]:flex"
                >
                    <span className={`${opsLabelClass} px-2 pt-1 pb-1.5`}>
                        Products in this plan
                    </span>
                    {products.map((item) => {
                        const [note, tone] = STATE_NOTE[item.state];

                        return (
                            <button
                                key={item.id}
                                type="button"
                                aria-current={
                                    item.id === product.id ? 'true' : undefined
                                }
                                onClick={() => pick(item.id)}
                                className={`flex min-h-12 items-center gap-2.5 rounded-[10px] px-2 py-1.5 text-left ${item.id === product.id ? 'border border-[#111] bg-[#f2f2f2]' : 'border border-transparent hover:bg-[#fafafa]'}`}
                            >
                                <ProductThumb product={item} />
                                <span className="flex min-w-0 flex-1 flex-col">
                                    <span className="truncate text-[13.5px] font-semibold">
                                        {item.name}
                                    </span>
                                    <span className={`text-[11px] ${tone}`}>
                                        {item.state === 'set' ||
                                        item.state === 'partial'
                                            ? item.sizes.length === 1
                                                ? 'Recipe set'
                                                : `${item.sizes.filter((entry) => entry.lines?.length).length} of ${item.sizes.length} sizes set`
                                            : note}
                                    </span>
                                </span>
                            </button>
                        );
                    })}
                    <span className="px-2 pt-1.5 text-[11px] leading-4 text-[#8a8a8a]">
                        Only existing Catalog products appear here. Operations
                        does not keep a second product list.
                    </span>
                </nav>
                <div
                    className="owner-hide-scrollbar flex gap-1.5 overflow-x-auto min-[820px]:hidden"
                    role="group"
                    aria-label="Products in this plan"
                >
                    {products.map((item) => (
                        <button
                            key={item.id}
                            type="button"
                            aria-pressed={item.id === product.id}
                            onClick={() => pick(item.id)}
                            className={`inline-flex h-10 shrink-0 items-center rounded-[10px] border px-3 text-[12.5px] font-semibold whitespace-nowrap ${item.id === product.id ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white'}`}
                        >
                            {item.name}
                        </button>
                    ))}
                </div>

                <section
                    className={opsCardClass}
                    aria-labelledby="recipe-product"
                >
                    <div className="flex flex-wrap items-center gap-3">
                        <ProductThumb product={product} large />
                        <div className="flex min-w-0 flex-[1_1_200px] flex-col gap-0.5">
                            <span className={opsLabelClass}>
                                Recipe · existing product
                            </span>
                            <h2
                                id="recipe-product"
                                className="text-lg font-bold tracking-[-0.015em] wrap-anywhere"
                            >
                                {product.name}
                            </h2>
                            <span className="text-xs leading-5 text-[#767676]">
                                {[
                                    product.category,
                                    product.sizes
                                        .map((item) =>
                                            product.sizes.length > 1
                                                ? `${item.name} ${formatPeso(item.price_cents, true)}`
                                                : formatPeso(
                                                      item.price_cents,
                                                      true,
                                                  ),
                                        )
                                        .join(' · '),
                                    'from Catalog › Products',
                                ]
                                    .filter(Boolean)
                                    .join(' · ')}
                                {!product.is_active && ' · inactive product'}
                            </span>
                        </div>
                    </div>

                    {directReason ? (
                        <div className="flex flex-col items-center gap-2 rounded-xl bg-[#f7f7f7] px-4 py-8 text-center">
                            <Box className="size-6" aria-hidden="true" />
                            <span className="text-[15px] font-semibold">
                                No recipe needed
                            </span>
                            <span className="max-w-[48ch] text-[12.5px] leading-5 text-[#666]">
                                {directReason} Direct-resale sales are reported
                                as not costed, because there is no trusted
                                product cost.
                            </span>
                            {product.no_recipe_needed &&
                                !product.tracked_at.length && (
                                    <button
                                        type="button"
                                        className={opsButtonClass}
                                        disabled={busy}
                                        onClick={() => setMode(false)}
                                    >
                                        Add a recipe instead
                                    </button>
                                )}
                        </div>
                    ) : (
                        <div className="flex flex-col gap-3">
                            <div className="flex flex-col gap-1.5">
                                <span className={opsLabelClass}>
                                    One recipe per size
                                </span>
                                <div
                                    className="grid gap-2"
                                    style={{
                                        gridTemplateColumns: `repeat(${product.sizes.length}, minmax(0, 1fr))`,
                                    }}
                                >
                                    {product.sizes.map((item) => {
                                        const itemCosts = (
                                            item.lines ?? []
                                        ).map((row) =>
                                            estimateLineCents(
                                                row.quantity,
                                                byId.get(row.ingredient_id)
                                                    ?.purchase_unit ?? null,
                                            ),
                                        );

                                        return (
                                            <button
                                                key={item.key}
                                                type="button"
                                                aria-pressed={
                                                    item.key === size.key
                                                }
                                                onClick={() => {
                                                    setSizeKey(item.key);
                                                    setErrors({});
                                                    setAdding('');
                                                }}
                                                className={`flex min-h-14 min-w-0 flex-col items-start gap-0.5 rounded-xl bg-white px-2.5 py-2 text-left ${item.key === size.key ? 'border-[1.5px] border-[#111]' : 'border border-[#e5e5e5]'}`}
                                            >
                                                <span className="flex w-full items-center justify-between gap-2">
                                                    <span className="text-[13.5px] font-bold">
                                                        {item.name}
                                                    </span>
                                                    <span
                                                        className={`size-2 rounded-full ${item.lines?.length ? 'bg-[#15803d]' : 'bg-[#b45309]'}`}
                                                        aria-hidden="true"
                                                    />
                                                </span>
                                                <span
                                                    className={`text-[11px] ${item.lines?.length ? 'text-[#666]' : 'font-semibold text-[#b45309]'}`}
                                                >
                                                    {item.lines?.length
                                                        ? `${itemCosts.some((cost) => cost === null) ? 'Cost incomplete' : formatPeso(itemCosts.reduce<number>((sum, cost) => sum + (cost ?? 0), 0))} · ${item.lines.length} ingredients`
                                                        : 'No recipe'}
                                                </span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {editing ? (
                                <div className="flex flex-col gap-3">
                                    <div className="overflow-hidden rounded-xl border border-[#e5e5e5]">
                                        {rows.map((row, index) => {
                                            const ingredient = byId.get(
                                                row.ingredient_id,
                                            );

                                            return (
                                                <div
                                                    key={row.ingredient_id}
                                                    className="grid grid-cols-[minmax(0,1fr)_auto_44px] items-center gap-x-2.5 gap-y-2 border-b border-[#f2f2f2] px-3 py-2.5 min-[820px]:grid-cols-[minmax(0,1.4fr)_170px_96px_44px]"
                                                >
                                                    <span className="col-span-2 flex min-w-0 items-center gap-2.5 min-[820px]:col-span-1">
                                                        <IngredientIcon
                                                            icon={
                                                                ingredient?.icon ??
                                                                'box'
                                                            }
                                                            size={34}
                                                        />
                                                        <span className="flex min-w-0 flex-col">
                                                            <span
                                                                className="truncate text-[13.5px] font-semibold"
                                                                title={
                                                                    ingredient?.name
                                                                }
                                                            >
                                                                {ingredient?.name ??
                                                                    'Archived ingredient'}
                                                            </span>
                                                            <span className="text-[11px] text-[#767676]">
                                                                {ingredient?.stock
                                                                    ? `In stock: ${formatQuantity(ingredient.stock.current, ingredient.base_unit)}`
                                                                    : ingredient?.base_unit}
                                                                {ingredient &&
                                                                ingredient
                                                                    .plan_ids
                                                                    .length > 1
                                                                    ? ' · shared'
                                                                    : ''}
                                                            </span>
                                                        </span>
                                                    </span>
                                                    <label className="flex items-center gap-2">
                                                        <span className="sr-only">
                                                            Quantity per sale of{' '}
                                                            {ingredient?.name}
                                                        </span>
                                                        <input
                                                            className={`${opsInputClass} w-24 text-right tabular-nums`}
                                                            inputMode="decimal"
                                                            value={row.quantity}
                                                            onChange={(event) =>
                                                                setRows(
                                                                    rows.map(
                                                                        (
                                                                            item,
                                                                            position,
                                                                        ) =>
                                                                            position ===
                                                                            index
                                                                                ? {
                                                                                      ...item,
                                                                                      quantity:
                                                                                          event
                                                                                              .target
                                                                                              .value,
                                                                                  }
                                                                                : item,
                                                                    ),
                                                                )
                                                            }
                                                        />
                                                        <span className="text-[12.5px] text-[#767676]">
                                                            {
                                                                ingredient?.base_unit
                                                            }
                                                        </span>
                                                    </label>
                                                    <span
                                                        className={`text-right text-[13px] font-semibold whitespace-nowrap tabular-nums ${costs[index] === null ? 'text-[#b45309]' : ''}`}
                                                    >
                                                        {costs[index] === null
                                                            ? 'Cost unknown'
                                                            : formatPeso(
                                                                  costs[index],
                                                              )}
                                                    </span>
                                                    <button
                                                        type="button"
                                                        aria-label={`Remove ${ingredient?.name ?? 'ingredient'}`}
                                                        onClick={() =>
                                                            setRows(
                                                                rows.filter(
                                                                    (
                                                                        _,
                                                                        position,
                                                                    ) =>
                                                                        position !==
                                                                        index,
                                                                ),
                                                            )
                                                        }
                                                        className="col-start-3 row-start-1 flex size-11 items-center justify-center rounded-[10px] border border-[#e5e5e5] text-[#767676] min-[820px]:col-start-auto min-[820px]:row-start-auto"
                                                    >
                                                        <Trash2 className="size-4" />
                                                    </button>
                                                </div>
                                            );
                                        })}
                                        {rows.length === 0 && (
                                            <p className="border-b border-[#f2f2f2] p-3.5 text-[12.5px] text-[#767676]">
                                                No ingredients yet. Add the
                                                first one below.
                                            </p>
                                        )}
                                        <div className="flex flex-wrap items-center gap-2 bg-[#fafafa] px-3 py-2.5">
                                            <label className="min-w-0 flex-[1_1_220px]">
                                                <span className="sr-only">
                                                    Ingredient to add
                                                </span>
                                                <select
                                                    className={opsInputClass}
                                                    value={adding}
                                                    onChange={(event) =>
                                                        setAdding(
                                                            event.target.value,
                                                        )
                                                    }
                                                >
                                                    <option value="">
                                                        {available.length
                                                            ? 'Choose an ingredient to add'
                                                            : 'Every ingredient is in this recipe'}
                                                    </option>
                                                    {available.map(
                                                        (ingredient) => (
                                                            <option
                                                                key={
                                                                    ingredient.id
                                                                }
                                                                value={
                                                                    ingredient.id
                                                                }
                                                            >
                                                                {
                                                                    ingredient.name
                                                                }{' '}
                                                                (
                                                                {
                                                                    ingredient.base_unit
                                                                }
                                                                )
                                                                {ingredient.plan_ids.includes(
                                                                    plan.id,
                                                                )
                                                                    ? ''
                                                                    : ' · from another plan'}
                                                            </option>
                                                        ),
                                                    )}
                                                </select>
                                            </label>
                                            <button
                                                type="button"
                                                className={opsButtonClass}
                                                disabled={!adding}
                                                onClick={() => {
                                                    setRows([
                                                        ...rows,
                                                        {
                                                            ingredient_id:
                                                                adding,
                                                            quantity: '1',
                                                        },
                                                    ]);
                                                    setAdding('');
                                                }}
                                            >
                                                <Plus className="size-4" /> Add
                                                ingredient
                                            </button>
                                        </div>
                                    </div>
                                    <div className="grid grid-cols-1 gap-2 min-[560px]:grid-cols-3">
                                        <div className="flex flex-col gap-1 rounded-xl border border-[#111] p-3">
                                            <span className={opsLabelClass}>
                                                Est. ingredient cost
                                            </span>
                                            <span className="text-[19px] font-bold tabular-nums">
                                                {formatPeso(total)}
                                            </span>
                                            <span className="text-[11px] text-[#767676]">
                                                {unknown
                                                    ? 'Some costs unknown · incomplete · '
                                                    : ''}
                                                per {sizeLabel}
                                            </span>
                                        </div>
                                        <div className="flex flex-col gap-1 rounded-xl bg-[#f7f7f7] p-3">
                                            <span className={opsLabelClass}>
                                                Selling price
                                            </span>
                                            <span className="text-[19px] font-bold tabular-nums">
                                                {formatPeso(size.price_cents)}
                                            </span>
                                            <span className="text-[11px] text-[#767676]">
                                                From Catalog › Products
                                                {operations.branch
                                                    ? ` · ${operations.branch.code}`
                                                    : ''}
                                            </span>
                                        </div>
                                        <div className="flex flex-col gap-1 rounded-xl bg-[#f7f7f7] p-3">
                                            <span className={opsLabelClass}>
                                                Est. gross margin
                                            </span>
                                            <span className="text-[19px] font-bold tabular-nums">
                                                {unknown
                                                    ? '—'
                                                    : formatPeso(
                                                          size.price_cents -
                                                              total,
                                                      )}
                                            </span>
                                            <span className="text-[11px] text-[#767676]">
                                                {unknown
                                                    ? 'Needs every ingredient cost'
                                                    : size.price_cents > 0
                                                      ? `${Math.round(((size.price_cents - total) / size.price_cents) * 100)}% of price`
                                                      : 'No selling price'}
                                            </span>
                                        </div>
                                    </div>
                                    <p className="text-xs leading-5 text-[#666]">
                                        Each {sizeLabel} sold subtracts exactly
                                        these quantities from branch ingredient
                                        stock. Recipe changes apply to future
                                        sales; past sales keep the recipe and
                                        cost they were sold with.
                                    </p>
                                    {Object.values(errors).length > 0 && (
                                        <p
                                            role="alert"
                                            className="text-xs font-semibold text-[#b91c1c]"
                                        >
                                            {Object.values(errors)[0]}
                                        </p>
                                    )}
                                    {dirty && (
                                        <div className="sticky bottom-2 flex flex-wrap items-center gap-2 rounded-xl border border-[#111] bg-white p-2.5 shadow-lg">
                                            <span className="mr-auto text-xs font-semibold text-[#b45309]">
                                                Unsaved changes
                                            </span>
                                            <button
                                                type="button"
                                                className={opsButtonClass}
                                                disabled={busy}
                                                onClick={() => setDraft(null)}
                                            >
                                                Discard
                                            </button>
                                            <button
                                                type="button"
                                                className={opsPrimaryClass}
                                                disabled={busy}
                                                onClick={save}
                                            >
                                                <Check className="size-4" />{' '}
                                                {rows.length
                                                    ? 'Save recipe'
                                                    : 'Remove recipe'}
                                            </button>
                                        </div>
                                    )}
                                </div>
                            ) : (
                                <div className="flex flex-col items-center gap-2 rounded-xl bg-[#fbf6e9] px-4 py-8 text-center">
                                    <CircleAlert
                                        className="size-6 text-[#b45309]"
                                        aria-hidden="true"
                                    />
                                    <span className="text-[15px] font-semibold">
                                        {sizeLabel} has no recipe yet.
                                    </span>
                                    <span className="max-w-[48ch] text-[12.5px] leading-5 text-[#666]">
                                        Selling it still works: the sale amount
                                        is recorded, but no ingredient stock
                                        moves and its cost is missing from
                                        estimated COGS.
                                    </span>
                                    <div className="mt-1 flex flex-wrap justify-center gap-2">
                                        {other && (
                                            <button
                                                type="button"
                                                className={opsButtonClass}
                                                onClick={() =>
                                                    setRows(
                                                        other.lines!.map(
                                                            (row) => ({
                                                                ...row,
                                                            }),
                                                        ),
                                                    )
                                                }
                                            >
                                                Copy from {other.name}
                                            </button>
                                        )}
                                        {!product.sizes.some(
                                            (item) => item.lines?.length,
                                        ) && (
                                            <button
                                                type="button"
                                                className={opsButtonClass}
                                                disabled={busy}
                                                onClick={() => setMode(true)}
                                            >
                                                No recipe needed
                                            </button>
                                        )}
                                        <button
                                            type="button"
                                            className={opsPrimaryClass}
                                            onClick={() => setRows([])}
                                        >
                                            <Plus className="size-4" /> Start
                                            recipe
                                        </button>
                                    </div>
                                    {Object.values(errors).length > 0 && (
                                        <p
                                            role="alert"
                                            className="text-xs font-semibold text-[#b91c1c]"
                                        >
                                            {Object.values(errors)[0]}
                                        </p>
                                    )}
                                </div>
                            )}
                        </div>
                    )}
                </section>
            </div>
        </OperationsShell>
    );
}

function ProductThumb({
    product,
    large = false,
}: {
    product: RecipeProduct;
    large?: boolean;
}) {
    const size = large ? 'size-14 rounded-xl' : 'size-9 rounded-[9px]';

    return (
        <span
            className={`${size} flex shrink-0 items-center justify-center overflow-hidden border border-[#efefef] bg-[#f7f7f7]`}
        >
            {product.image_url ? (
                <img
                    src={product.image_url}
                    alt=""
                    loading="lazy"
                    className="size-full object-cover"
                />
            ) : (
                <CupSoda className="size-4" aria-hidden="true" />
            )}
        </span>
    );
}
