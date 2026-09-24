import { Link, router } from '@inertiajs/react';
import { ArrowRight, Check, Info, Layers } from 'lucide-react';
import { useRef, useState } from 'react';
import {
    BranchRequired,
    Chip,
    EmptyState,
    IngredientIcon,
    MovementList,
    OperationsDialog,
    OperationsShell,
    Segmented,
    StatusChip,
    opsButtonClass,
    opsCardClass,
    opsInputClass,
    opsLabelClass,
    opsPrimaryClass,
} from '@/components/operations-ui';
import { createClientUuid } from '@/lib/client-uuid';
import {
    formatDelta,
    formatQuantity,
    formatScaled,
    parseQuantity,
    parseSignedQuantity,
} from '@/lib/operations';
import { index as inventoryIndex } from '@/routes/inventory';
import operationsRoutes from '@/routes/operations';
import type {
    IngredientMovementGroup,
    OperationsContext,
    OperationsIngredient,
} from '@/types/operations';

type Props = {
    operations: OperationsContext;
    ingredients: OperationsIngredient[];
    movements: IngredientMovementGroup[];
};

type Bucket = 'ok' | 'hold' | 'buy' | 'out' | 'setup';

const BUCKETS: [Bucket, string, string][] = [
    ['ok', 'At or above target', 'bg-[#15803d]'],
    ['hold', 'Below target · no buy yet', 'bg-[#111]'],
    ['buy', 'To buy', 'bg-[#b45309]'],
    ['out', 'Out or below zero', 'bg-[#b91c1c]'],
    ['setup', 'Needs setup', 'bg-[#949494]'],
];

function bucketOf(ingredient: OperationsIngredient): Bucket {
    switch (ingredient.status?.key) {
        case 'setup':
            return 'setup';
        case 'negative':
        case 'out':
            return 'out';
        case 'buy':
            return 'buy';
        case 'below':
            return 'hold';
        default:
            return 'ok';
    }
}

const RULES: [
    string,
    'plain' | 'amber' | 'red' | 'green' | 'outline',
    string,
    string,
][] = [
    [
        'Sale',
        'plain',
        'A committed sale subtracts its recipe. Pay Now and Pay Later take the same path; settling later moves nothing.',
        'Lemon Yakult (Medium) → Lemon −0.5, Yakult −1',
    ],
    [
        'Edit',
        'amber',
        'Only the difference moves. The original sale movement stays as it was.',
        '2 → 1 Medium: Lemon +0.5, Yakult +1',
    ],
    [
        'Void',
        'red',
        'Restores exactly what the order still consumed, once, from the recipe it was sold with.',
        'Voided order → its recorded usage comes back',
    ],
    [
        'Purchase',
        'green',
        'A confirmed pamamalengke adds stock in base units.',
        '2 trays of eggs (30 pcs each) → Eggs +60',
    ],
    [
        'Wastage',
        'amber',
        'Spoiled, spilled or cracked stock is subtracted with a reason.',
        'Eggs −2 · cracked in transit',
    ],
    [
        'Count correction',
        'outline',
        'A physical count that differs appends the difference.',
        'Counted 54, system 56 → −2',
    ],
];

export default function OperationsStock({
    operations,
    ingredients,
    movements,
}: Props) {
    const plan = operations.plans.find(
        (item) => item.id === operations.active_plan_id,
    )!;
    const [filter, setFilter] = useState<Bucket | 'all'>('all');
    const [adjusting, setAdjusting] = useState<OperationsIngredient | null>(
        null,
    );
    const planName = (id: string) =>
        operations.plans.find((item) => item.id === id)?.name ?? 'Plan';
    const list = ingredients.filter(
        (ingredient) => filter === 'all' || bucketOf(ingredient) === filter,
    );

    return (
        <OperationsShell
            operations={operations}
            title="Ingredient Stock"
            description={`Branch ingredient stock used by the ${plan.name} plan: start of day, what moved today, and where it stands against target.`}
        >
            {!operations.branch ? (
                <BranchRequired what="Ingredient stock" />
            ) : (
                <>
                    <div className="grid grid-cols-2 gap-2 min-[520px]:grid-cols-3 min-[900px]:grid-cols-5">
                        {BUCKETS.map(([key, label, dot]) => {
                            const on = filter === key;

                            return (
                                <button
                                    key={key}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() => setFilter(on ? 'all' : key)}
                                    className={`flex min-w-0 flex-col items-start gap-1 rounded-[13px] border bg-white p-2.5 text-left md:p-3 ${on ? 'border-[#111] ring-1 ring-[#111]' : 'border-[#e5e5e5]'}`}
                                >
                                    <span className="flex w-full min-w-0 items-center gap-1.5">
                                        <span
                                            className={`size-2 shrink-0 rounded-full ${dot}`}
                                            aria-hidden="true"
                                        />
                                        <span className="truncate text-[10.5px] font-semibold tracking-[0.05em] text-[#767676] uppercase">
                                            {label}
                                        </span>
                                    </span>
                                    <span className="text-[21px] font-bold tracking-[-0.02em] tabular-nums">
                                        {
                                            ingredients.filter(
                                                (ingredient) =>
                                                    bucketOf(ingredient) ===
                                                    key,
                                            ).length
                                        }
                                    </span>
                                </button>
                            );
                        })}
                    </div>
                    <div className="flex flex-wrap items-center gap-2.5 rounded-xl bg-[#f7f7f7] px-3 py-2.5">
                        <Info className="size-4 shrink-0" aria-hidden="true" />
                        <span className="min-w-0 flex-[1_1_260px] text-xs leading-5 text-[#444]">
                            These are the same ingredient records listed in
                            Catalog › Inventory. There is one stock per branch;
                            plans only filter the view. Quantities are stored
                            exactly, so 29.5 stays 29.5.
                        </span>
                        <Link
                            href={inventoryIndex({
                                query: { type: 'ingredients' },
                            })}
                            className={opsButtonClass}
                        >
                            Open in Inventory <ArrowRight className="size-4" />
                        </Link>
                    </div>

                    {list.length === 0 ? (
                        <EmptyState
                            title={
                                ingredients.length
                                    ? 'No ingredient is in this state right now.'
                                    : 'This plan has no ingredients yet.'
                            }
                            action={
                                ingredients.length ? (
                                    <button
                                        type="button"
                                        className={opsButtonClass}
                                        onClick={() => setFilter('all')}
                                    >
                                        Show all ingredients
                                    </button>
                                ) : undefined
                            }
                        />
                    ) : (
                        <div className="overflow-hidden rounded-2xl border border-[#e5e5e5] bg-white">
                            <table className="hidden w-full text-left min-[1040px]:table">
                                <caption className="sr-only">
                                    Ingredient stock today at{' '}
                                    {operations.branch.name}
                                </caption>
                                <thead className="bg-[#fafafa]">
                                    <tr>
                                        {[
                                            'Ingredient',
                                            'Start',
                                            'Consumed',
                                            'Purchased',
                                            'Wastage',
                                            'Giveaway',
                                            'Correction',
                                            'Current',
                                            'Target',
                                            '',
                                        ].map((label, index) => (
                                            <th
                                                key={label || 'actions'}
                                                scope="col"
                                                className={`px-3 py-2.5 ${opsLabelClass} ${index > 0 && index < 9 ? 'text-right' : ''}`}
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
                                    {list.map((ingredient) => {
                                        const stock = ingredient.stock!;

                                        return (
                                            <tr
                                                key={ingredient.id}
                                                className="border-t border-[#f2f2f2]"
                                            >
                                                <td className="px-3 py-2.5">
                                                    <span className="flex min-w-0 items-center gap-2.5">
                                                        <IngredientIcon
                                                            icon={
                                                                ingredient.icon
                                                            }
                                                            size={34}
                                                        />
                                                        <span className="flex min-w-0 flex-col">
                                                            <span
                                                                className="max-w-[200px] truncate text-[13.5px] font-semibold"
                                                                title={
                                                                    ingredient.name
                                                                }
                                                            >
                                                                {
                                                                    ingredient.name
                                                                }
                                                            </span>
                                                            <span className="max-w-[220px] truncate text-[11px] text-[#767676]">
                                                                {
                                                                    ingredient.base_unit
                                                                }
                                                                {ingredient
                                                                    .plan_ids
                                                                    .length > 1
                                                                    ? ` · shared with ${ingredient.plan_ids
                                                                          .filter(
                                                                              (
                                                                                  id,
                                                                              ) =>
                                                                                  id !==
                                                                                  plan.id,
                                                                          )
                                                                          .map(
                                                                              planName,
                                                                          )
                                                                          .join(
                                                                              ', ',
                                                                          )}`
                                                                    : ''}
                                                            </span>
                                                        </span>
                                                    </span>
                                                </td>
                                                <NumberCell
                                                    value={stock.start}
                                                />
                                                <NumberCell
                                                    value={negate(
                                                        stock.consumed,
                                                    )}
                                                    tone="text-[#b91c1c]"
                                                    signed
                                                />
                                                <NumberCell
                                                    value={stock.purchased}
                                                    tone="text-[#15803d]"
                                                    signed
                                                />
                                                <NumberCell
                                                    value={stock.wastage}
                                                    tone="text-[#b45309]"
                                                    signed
                                                />
                                                <NumberCell
                                                    value={stock.giveaway}
                                                    tone="text-[#be123c]"
                                                    signed
                                                />
                                                <NumberCell
                                                    value={stock.correction}
                                                    tone="text-[#111]"
                                                    signed
                                                />
                                                <td className="px-3 py-2.5 text-right">
                                                    <span className="flex flex-col items-end gap-1">
                                                        <span
                                                            className={`text-[13.5px] font-bold whitespace-nowrap tabular-nums ${ingredient.status?.tone === 'red' ? 'text-[#b91c1c]' : ingredient.status?.tone === 'amber' ? 'text-[#b45309]' : ''}`}
                                                        >
                                                            {formatQuantity(
                                                                stock.current,
                                                                ingredient.base_unit,
                                                            )}
                                                        </span>
                                                        <StatusChip
                                                            ingredient={
                                                                ingredient
                                                            }
                                                        />
                                                    </span>
                                                </td>
                                                <NumberCell
                                                    value={ingredient.target}
                                                />
                                                <td className="px-3 py-2.5 text-right">
                                                    <button
                                                        type="button"
                                                        className={
                                                            opsButtonClass
                                                        }
                                                        onClick={() =>
                                                            setAdjusting(
                                                                ingredient,
                                                            )
                                                        }
                                                    >
                                                        <Layers className="size-4" />{' '}
                                                        Adjust
                                                    </button>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                            <ul className="flex flex-col min-[1040px]:hidden">
                                {list.map((ingredient) => {
                                    const stock = ingredient.stock!;

                                    return (
                                        <li
                                            key={ingredient.id}
                                            className="flex flex-col gap-2.5 border-b border-[#f2f2f2] p-3 last:border-b-0"
                                        >
                                            <div className="flex items-start gap-2.5">
                                                <IngredientIcon
                                                    icon={ingredient.icon}
                                                    size={34}
                                                />
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="text-sm leading-snug font-semibold wrap-anywhere">
                                                        {ingredient.name}
                                                    </span>
                                                    <span className="text-[11px] leading-4 text-[#767676]">
                                                        {ingredient.base_unit}
                                                        {ingredient.plan_ids
                                                            .length > 1
                                                            ? ` · shared with ${ingredient.plan_ids
                                                                  .filter(
                                                                      (id) =>
                                                                          id !==
                                                                          plan.id,
                                                                  )
                                                                  .map(planName)
                                                                  .join(', ')}`
                                                            : ''}
                                                    </span>
                                                </span>
                                                <StatusChip
                                                    ingredient={ingredient}
                                                />
                                            </div>
                                            <dl className="grid grid-cols-3 gap-1.5 rounded-[10px] bg-[#fafafa] p-2 min-[430px]:grid-cols-6">
                                                {(
                                                    [
                                                        [
                                                            'Start',
                                                            formatScaledDisplay(
                                                                stock.start,
                                                            ),
                                                            'text-[#111]',
                                                        ],
                                                        [
                                                            'Used',
                                                            formatDelta(
                                                                negate(
                                                                    stock.consumed,
                                                                ),
                                                            ),
                                                            'text-[#b91c1c]',
                                                        ],
                                                        [
                                                            'Bought',
                                                            formatDelta(
                                                                stock.purchased,
                                                            ),
                                                            'text-[#15803d]',
                                                        ],
                                                        [
                                                            'Waste',
                                                            formatDelta(
                                                                stock.wastage,
                                                            ),
                                                            'text-[#b45309]',
                                                        ],
                                                        [
                                                            'Given',
                                                            formatDelta(
                                                                stock.giveaway,
                                                            ),
                                                            'text-[#be123c]',
                                                        ],
                                                        [
                                                            'Count',
                                                            formatDelta(
                                                                stock.correction,
                                                            ),
                                                            'text-[#111]',
                                                        ],
                                                    ] as const
                                                ).map(
                                                    ([label, value, tone]) => (
                                                        <div
                                                            key={label}
                                                            className="flex min-w-0 flex-col gap-0.5"
                                                        >
                                                            <dt className="text-[9.5px] font-semibold tracking-[0.05em] text-[#767676] uppercase">
                                                                {label}
                                                            </dt>
                                                            <dd
                                                                className={`truncate text-[13px] font-bold tabular-nums ${value === '0' ? 'text-[#949494]' : tone}`}
                                                            >
                                                                {value}
                                                            </dd>
                                                        </div>
                                                    ),
                                                )}
                                            </dl>
                                            <div className="flex items-center gap-2.5">
                                                <span className="flex min-w-0 flex-1 flex-col">
                                                    <span className="text-[10px] font-semibold tracking-[0.06em] text-[#767676] uppercase">
                                                        Current / target
                                                    </span>
                                                    <span className="text-[15px] font-bold tabular-nums">
                                                        {formatQuantity(
                                                            stock.current,
                                                            ingredient.base_unit,
                                                        )}{' '}
                                                        /{' '}
                                                        {formatQuantity(
                                                            ingredient.target,
                                                            ingredient.base_unit,
                                                        )}
                                                    </span>
                                                </span>
                                                <button
                                                    type="button"
                                                    className={opsButtonClass}
                                                    onClick={() =>
                                                        setAdjusting(ingredient)
                                                    }
                                                >
                                                    <Layers className="size-4" />{' '}
                                                    Adjust
                                                </button>
                                            </div>
                                        </li>
                                    );
                                })}
                            </ul>
                        </div>
                    )}

                    <section
                        className={opsCardClass}
                        aria-labelledby="how-stock-moves"
                    >
                        <div className="flex flex-col gap-0.5">
                            <h2
                                id="how-stock-moves"
                                className="text-[14.5px] font-bold"
                            >
                                How stock moves
                            </h2>
                            <p className="text-xs leading-5 text-[#767676]">
                                Every change is a new movement; history is never
                                rewritten.
                            </p>
                        </div>
                        <div className="grid grid-cols-1 gap-2 min-[480px]:grid-cols-2 min-[760px]:grid-cols-3">
                            {RULES.map(([title, tone, body, example]) => (
                                <div
                                    key={title}
                                    className="flex flex-col gap-1.5 rounded-xl bg-[#fafafa] p-3"
                                >
                                    <span className="flex items-center gap-2">
                                        <Chip tone={tone}>{title}</Chip>
                                    </span>
                                    <span className="text-xs leading-5 text-[#555]">
                                        {body}
                                    </span>
                                    <span className="text-[11.5px] leading-5 font-semibold tabular-nums">
                                        {example}
                                    </span>
                                </div>
                            ))}
                        </div>
                    </section>

                    <section
                        className={opsCardClass}
                        aria-labelledby="today-moves"
                    >
                        <div className="flex flex-col gap-0.5">
                            <h2
                                id="today-moves"
                                className="text-[14.5px] font-bold"
                            >
                                Today's movements
                            </h2>
                            <p className="text-xs leading-5 text-[#767676]">
                                Every movement that touched this plan's
                                ingredients today, newest first. Shared
                                ingredients also show movements from other
                                plans.
                            </p>
                        </div>
                        <MovementList movements={movements} planId={plan.id} />
                    </section>
                </>
            )}

            {adjusting && operations.branch && (
                <AdjustDialog
                    ingredient={adjusting}
                    branchName={operations.branch.name}
                    onClose={() => setAdjusting(null)}
                />
            )}
        </OperationsShell>
    );
}

function negate(display: string): string {
    const scaled = parseSignedQuantity(display);

    return scaled === 0 ? '0' : formatScaled(-scaled).replaceAll(',', '');
}

function formatScaledDisplay(display: string): string {
    return formatScaled(parseSignedQuantity(display));
}

function NumberCell({
    value,
    tone,
    signed = false,
}: {
    value: string;
    tone?: string;
    signed?: boolean;
}) {
    const zero = parseSignedQuantity(value) === 0;

    return (
        <td
            className={`px-3 py-2.5 text-right text-[13px] tabular-nums ${zero ? 'text-[#949494]' : `font-semibold ${tone ?? 'text-[#666]'}`}`}
        >
            {signed ? formatDelta(value) : formatScaledDisplay(value)}
        </td>
    );
}

const WASTAGE_REASONS = [
    'Spoiled',
    'Spilled or dropped',
    'Expired',
    'Cracked or damaged',
    'Staff drink',
    'Other',
];
const COUNT_REASONS = [
    'End-of-day count',
    'Opening count',
    'Found extra',
    'Recording error',
    'Other',
];

function AdjustDialog({
    ingredient,
    branchName,
    onClose,
}: {
    ingredient: OperationsIngredient;
    branchName: string;
    onClose: () => void;
}) {
    const [mode, setMode] = useState<'wastage' | 'count'>('wastage');
    const [quantity, setQuantity] = useState('');
    const [reason, setReason] = useState(WASTAGE_REASONS[0]);
    const [note, setNote] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    /** One key per adjustment attempt, kept across retries so a repeated submit never records twice. */
    const key = useRef(createClientUuid());
    const current = parseSignedQuantity(ingredient.stock?.current ?? '0');
    const entered = parseQuantity(quantity);
    const result =
        entered === null
            ? null
            : mode === 'wastage'
              ? current - entered
              : entered;

    const confirm = () => {
        if (entered === null || (mode === 'wastage' && entered <= 0)) {
            setErrors({
                quantity:
                    mode === 'wastage'
                        ? 'Enter the quantity wasted.'
                        : 'Enter the actual counted stock.',
            });

            return;
        }
        if (mode === 'wastage' && entered > current) {
            setErrors({
                quantity:
                    "Wastage can't exceed current stock. Record a count correction instead.",
            });

            return;
        }
        if (mode === 'count' && entered === current) {
            setErrors({
                quantity: 'The count matches current stock. Nothing to record.',
            });

            return;
        }
        setBusy(true);
        router.post(
            operationsRoutes.ingredients.adjust.url(ingredient.id),
            {
                mode,
                quantity,
                reason,
                note: note.trim() || null,
                idempotency_key: key.current,
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
            kicker="Adjust ingredient stock"
            title={ingredient.name}
            description={`${branchName} branch · recorded with its reason, your name and the time.`}
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
                            onClick={confirm}
                            disabled={busy}
                        >
                            <Check className="size-4" /> Record adjustment
                        </button>
                    </div>
                </div>
            }
        >
            <div className="flex flex-col gap-4">
                <div className="flex items-center justify-between gap-3 rounded-xl bg-[#f7f7f7] p-3">
                    <span className="text-xs text-[#767676]">
                        Current stock
                    </span>
                    <span className="text-xl font-bold tabular-nums">
                        {formatQuantity(
                            ingredient.stock?.current ?? '0',
                            ingredient.base_unit,
                        )}
                    </span>
                </div>
                <Segmented
                    label="Adjustment type"
                    value={mode}
                    onChange={(next) => {
                        setMode(next);
                        setReason(
                            next === 'wastage'
                                ? WASTAGE_REASONS[0]
                                : COUNT_REASONS[0],
                        );
                        setErrors({});
                        key.current = createClientUuid();
                    }}
                    options={[
                        { value: 'wastage', label: 'Wastage' },
                        { value: 'count', label: 'Count correction' },
                    ]}
                />
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>
                        {mode === 'wastage'
                            ? `Quantity wasted (${ingredient.base_unit})`
                            : `Actual counted stock (${ingredient.base_unit})`}
                    </span>
                    <input
                        className={opsInputClass}
                        inputMode="decimal"
                        value={quantity}
                        onChange={(event) => setQuantity(event.target.value)}
                        placeholder="0"
                        autoFocus
                    />
                </label>
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Reason</span>
                    <select
                        className={opsInputClass}
                        value={reason}
                        onChange={(event) => setReason(event.target.value)}
                    >
                        {(mode === 'wastage'
                            ? WASTAGE_REASONS
                            : COUNT_REASONS
                        ).map((option) => (
                            <option key={option}>{option}</option>
                        ))}
                    </select>
                </label>
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Note (optional)</span>
                    <input
                        className={opsInputClass}
                        value={note}
                        maxLength={200}
                        onChange={(event) => setNote(event.target.value)}
                        placeholder="Cracked in transit"
                    />
                </label>
                <div className="flex items-center justify-between gap-3 rounded-xl border border-[#111] p-3">
                    <span className="text-[12.5px] font-semibold">
                        Resulting stock
                    </span>
                    <span className="text-[22px] font-bold tabular-nums">
                        {result === null
                            ? '—'
                            : `${formatScaled(result)} ${ingredient.base_unit}`}
                    </span>
                </div>
                {mode === 'count' && result !== null && (
                    <p className="text-xs text-[#666] tabular-nums">
                        Appends{' '}
                        {formatDelta(
                            formatScaled(result - current).replaceAll(',', ''),
                        )}{' '}
                        {ingredient.base_unit} (counted − system stock).
                    </p>
                )}
            </div>
        </OperationsDialog>
    );
}
