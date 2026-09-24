import { router } from '@inertiajs/react';
import { Archive, Check, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import {
    INGREDIENT_ICON_NAMES,
    IngredientIcon,
    OperationsDialog,
    formatQuantityOrDash,
    opsButtonClass,
    opsInputClass,
    opsLabelClass,
    opsPrimaryClass,
} from '@/components/operations-ui';
import {
    formatPeso,
    formatQuantity,
    parseMoney,
    parseQuantity,
    unitLabel,
} from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type {
    OperationsContext,
    OperationsIngredient,
} from '@/types/operations';

const UNITS = ['pc', 'pack', 'bottle', 'ml', 'L', 'g', 'kg'];

/**
 * Add or edit an Ingredient definition. Opening stock is recorded once, as an opening-balance movement for the selected
 * Branch; afterwards stock changes only through Adjust, sales and purchases. The base unit locks once it is used.
 */
export function IngredientDialog({
    ingredient,
    operations,
    onClose,
}: {
    ingredient: OperationsIngredient | null;
    operations: OperationsContext;
    onClose: () => void;
}) {
    const [name, setName] = useState(ingredient?.name ?? '');
    const [icon, setIcon] = useState(ingredient?.icon ?? 'box');
    const [unit, setUnit] = useState(ingredient?.base_unit ?? 'pc');
    const [initial, setInitial] = useState('');
    const [target, setTarget] = useState(ingredient?.target ?? '');
    const [puName, setPuName] = useState(
        ingredient?.purchase_unit?.name ?? (ingredient ? '' : 'pc'),
    );
    const [puSize, setPuSize] = useState(
        ingredient?.purchase_unit?.size ?? (ingredient ? '' : '1'),
    );
    const [puCost, setPuCost] = useState(
        ingredient?.purchase_unit?.cost_cents != null
            ? (ingredient.purchase_unit.cost_cents / 100).toFixed(2)
            : '',
    );
    const [rule, setRule] = useState<OperationsIngredient['rule']>(
        ingredient?.rule ?? 'top_up',
    );
    const [reorder, setReorder] = useState(ingredient?.reorder_point ?? '');
    const [plans, setPlans] = useState<string[]>(
        ingredient?.plan_ids ??
            (operations.active_plan_id ? [operations.active_plan_id] : []),
    );
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [busy, setBusy] = useState(false);
    const sizeScaled = parseQuantity(puSize);
    const costCents = puCost.trim() === '' ? null : parseMoney(puCost);

    const ruleText = (() => {
        if (rule === 'none') {
            return [
                'Never suggested automatically.',
                'Add it to a run by hand when needed. The target still shows on Ingredient Stock.',
            ];
        }
        if (!puName.trim() || !sizeScaled) {
            return [
                'Needs a purchase unit first.',
                'Tell Pamamalengke what you buy, for example 1 pack = 5 pcs.',
            ];
        }
        if (rule === 'reorder') {
            return [
                `Suggests a buy once stock is ${reorder || '…'} ${unitLabel(unit, 2)} or lower.`,
                `Then enough whole ${unitLabel(puName.trim(), 2)} to get back to ${target || '…'} ${unitLabel(unit, 2)}.`,
            ];
        }

        return [
            `Suggests a buy whenever stock is below ${target || '…'} ${unitLabel(unit, 2)}.`,
            `Any shortfall is rounded up to whole ${unitLabel(puName.trim(), 2)}.`,
        ];
    })();

    const save = () => {
        setBusy(true);
        const payload = {
            name,
            icon,
            base_unit: unit,
            target_quantity: target,
            purchase_unit_name: puName.trim() || null,
            purchase_unit_size: puSize.trim() || null,
            purchase_unit_cost: puCost.trim() || null,
            replenishment_rule: rule,
            reorder_point: rule === 'reorder' ? reorder : null,
            plan_ids: plans,
            ...(ingredient ? {} : { initial_quantity: initial.trim() || null }),
        };
        const options = {
            preserveScroll: true,
            onSuccess: () => onClose(),
            onError: (next: Record<string, string>) => setErrors(next),
            onFinish: () => setBusy(false),
        };
        if (ingredient) {
            router.put(
                operationsRoutes.ingredients.update.url(ingredient.id),
                payload,
                options,
            );
        } else {
            router.post(
                operationsRoutes.ingredients.store.url(),
                payload,
                options,
            );
        }
    };
    const archive = () => {
        if (!ingredient) {
            return;
        }
        setBusy(true);
        const route = ingredient.archived
            ? operationsRoutes.ingredients.restore
            : operationsRoutes.ingredients.archive;
        router.post(
            route.url(ingredient.id),
            {},
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
            kicker={ingredient ? 'Edit ingredient' : 'New ingredient'}
            title={ingredient ? ingredient.name : 'Add ingredient'}
            footer={
                <div className="flex flex-col gap-2">
                    {Object.values(errors).length > 0 && (
                        <div
                            role="alert"
                            className="text-xs font-semibold text-[#b91c1c]"
                        >
                            {Object.values(errors).map((message) => (
                                <p key={message}>{message}</p>
                            ))}
                        </div>
                    )}
                    <div className="flex gap-2">
                        {ingredient && (
                            <button
                                type="button"
                                className={opsButtonClass}
                                onClick={archive}
                                disabled={busy}
                            >
                                {ingredient.archived ? (
                                    <RotateCcw className="size-4" />
                                ) : (
                                    <Archive className="size-4" />
                                )}
                                {ingredient.archived ? 'Restore' : 'Archive'}
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
                            {ingredient ? 'Save ingredient' : 'Add ingredient'}
                        </button>
                    </div>
                </div>
            }
        >
            <div className="flex flex-col gap-4">
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>Ingredient name</span>
                    <input
                        className={opsInputClass}
                        value={name}
                        maxLength={80}
                        onChange={(event) => setName(event.target.value)}
                        placeholder="Lemon"
                    />
                </label>
                <fieldset className="flex flex-col gap-1.5">
                    <legend className={`${opsLabelClass} pb-1.5`}>
                        Used in plans
                    </legend>
                    <div className="flex flex-wrap gap-1.5">
                        {operations.plans.map((plan) => {
                            const on = plans.includes(plan.id);

                            return (
                                <button
                                    key={plan.id}
                                    type="button"
                                    aria-pressed={on}
                                    onClick={() =>
                                        setPlans((current) =>
                                            on
                                                ? current.filter(
                                                      (id) => id !== plan.id,
                                                  )
                                                : [...current, plan.id],
                                        )
                                    }
                                    className={`inline-flex min-h-10 items-center rounded-[10px] border px-3 text-[12.5px] font-semibold ${on ? 'border-[#111] bg-[#111] text-white' : 'border-[#d8d8d8] bg-white'}`}
                                >
                                    {plan.name} plan
                                </button>
                            );
                        })}
                    </div>
                    <p className="text-[11.5px] text-[#767676]">
                        {plans.length > 1
                            ? `One stock record, shown in ${plans.length} plans. Stock is never split or copied.`
                            : 'Plans only decide where this ingredient appears. Stock stays one branch record.'}
                    </p>
                </fieldset>
                <fieldset className="flex flex-col gap-1.5">
                    <legend className={`${opsLabelClass} pb-1.5`}>Icon</legend>
                    <div className="flex flex-wrap gap-1.5">
                        {INGREDIENT_ICON_NAMES.map((option) => (
                            <button
                                key={option}
                                type="button"
                                aria-label={option}
                                aria-pressed={icon === option}
                                onClick={() => setIcon(option)}
                                className={`rounded-[11px] border-2 ${icon === option ? 'border-[#111]' : 'border-transparent'}`}
                            >
                                <IngredientIcon icon={option} size={40} />
                            </button>
                        ))}
                    </div>
                </fieldset>
                <div className="grid grid-cols-1 gap-3 min-[560px]:grid-cols-2">
                    <label className="flex flex-col gap-1.5">
                        <span className={opsLabelClass}>Base unit</span>
                        <select
                            className={opsInputClass}
                            value={unit}
                            disabled={ingredient?.locked_unit}
                            onChange={(event) => setUnit(event.target.value)}
                        >
                            {UNITS.map((option) => (
                                <option key={option} value={option}>
                                    {option}
                                </option>
                            ))}
                        </select>
                        <span className="text-[11.5px] leading-4 text-[#767676]">
                            {ingredient?.locked_unit
                                ? 'Locked: stock history or a recipe already counts in this unit.'
                                : 'Stock and recipes count in this unit.'}
                        </span>
                    </label>
                    {ingredient ? (
                        <div className="flex flex-col gap-1.5">
                            <span className={opsLabelClass}>Current stock</span>
                            <span className="flex h-11 items-center rounded-[10px] bg-[#f7f7f7] px-3 text-[13.5px] font-semibold tabular-nums">
                                {formatQuantityOrDash(
                                    ingredient.stock?.current,
                                    ingredient.base_unit,
                                )}
                            </span>
                            <span className="text-[11.5px] leading-4 text-[#767676]">
                                Change it with Adjust on Ingredient Stock, so
                                the change is recorded.
                            </span>
                        </div>
                    ) : (
                        <label className="flex flex-col gap-1.5">
                            <span className={opsLabelClass}>
                                Opening stock
                                {operations.branch
                                    ? ` at ${operations.branch.code}`
                                    : ''}
                            </span>
                            <input
                                className={opsInputClass}
                                inputMode="decimal"
                                value={initial}
                                disabled={!operations.branch}
                                onChange={(event) =>
                                    setInitial(event.target.value)
                                }
                                placeholder={
                                    operations.branch ? '0' : 'Choose a branch'
                                }
                            />
                            <span className="text-[11.5px] leading-4 text-[#767676]">
                                {operations.branch
                                    ? 'Recorded as an opening-balance movement. Fractions are fine, for example 29.5.'
                                    : 'Opening stock needs one Branch. Use a count correction later.'}
                            </span>
                        </label>
                    )}
                </div>
                <label className="flex flex-col gap-1.5">
                    <span className={opsLabelClass}>
                        Target / par stock ({unit})
                    </span>
                    <input
                        className={opsInputClass}
                        inputMode="decimal"
                        value={target}
                        onChange={(event) => setTarget(event.target.value)}
                        placeholder="30"
                    />
                    <span className="text-[11.5px] leading-4 text-[#767676]">
                        The stock level you want to start a normal day with. It
                        guides planning; it doesn't trigger a buy on its own.
                    </span>
                </label>
                <fieldset className="flex flex-col gap-2.5 rounded-xl border border-[#e5e5e5] p-3">
                    <legend className="px-1 text-[13px] font-bold">
                        Purchase unit
                    </legend>
                    <div className="grid grid-cols-1 gap-2.5 min-[560px]:grid-cols-3">
                        <label className="flex min-w-0 flex-col gap-1.5">
                            <span className={opsLabelClass}>You buy it as</span>
                            <input
                                className={opsInputClass}
                                value={puName}
                                maxLength={30}
                                onChange={(event) =>
                                    setPuName(event.target.value)
                                }
                                placeholder="pack"
                            />
                        </label>
                        <label className="flex min-w-0 flex-col gap-1.5">
                            <span className={opsLabelClass}>
                                Each one holds ({unit})
                            </span>
                            <input
                                className={opsInputClass}
                                inputMode="decimal"
                                value={puSize}
                                onChange={(event) =>
                                    setPuSize(event.target.value)
                                }
                                placeholder="5"
                            />
                        </label>
                        <label className="flex min-w-0 flex-col gap-1.5">
                            <span className={opsLabelClass}>Cost each (₱)</span>
                            <input
                                className={opsInputClass}
                                inputMode="decimal"
                                value={puCost}
                                onChange={(event) =>
                                    setPuCost(event.target.value)
                                }
                                placeholder="Unknown"
                            />
                        </label>
                    </div>
                    <p className="text-xs font-semibold text-[#444] tabular-nums">
                        {puName.trim() && sizeScaled
                            ? `1 ${puName.trim()} = ${formatQuantity(puSize, unit)} · ${costCents === null ? 'cost unknown (never counted as ₱0)' : `${formatPeso(costCents)} per ${puName.trim()}`}`
                            : `Name what you buy and how many ${unitLabel(unit, 2)} it holds.`}
                    </p>
                </fieldset>
                <fieldset className="flex flex-col gap-2">
                    <legend className={`${opsLabelClass} pb-1.5`}>
                        Replenishment rule
                    </legend>
                    {(
                        [
                            [
                                'top_up',
                                'Top up to target',
                                'Suggest a buy whenever stock is below target. Suits items bought one at a time, like lemons.',
                            ],
                            [
                                'reorder',
                                'Reorder at a threshold',
                                'Wait until stock reaches a reorder point. Suits packs and bottles, like Yakult.',
                            ],
                            [
                                'none',
                                'No automatic suggestion',
                                'Never suggested. Add it to a run by hand when needed.',
                            ],
                        ] as const
                    ).map(([value, title, body]) => (
                        <label
                            key={value}
                            className={`flex cursor-pointer items-start gap-2.5 rounded-xl p-3 ${rule === value ? 'border-[1.5px] border-[#111]' : 'border border-[#d8d8d8]'}`}
                        >
                            <input
                                type="radio"
                                name="rule"
                                value={value}
                                checked={rule === value}
                                onChange={() => setRule(value)}
                                className="mt-1 size-4 accent-[#111]"
                            />
                            <span className="flex flex-col gap-0.5">
                                <span className="text-[13.5px] font-semibold">
                                    {title}
                                </span>
                                <span className="text-xs leading-5 text-[#767676]">
                                    {body}
                                </span>
                            </span>
                        </label>
                    ))}
                </fieldset>
                {rule === 'reorder' && (
                    <label className="flex flex-col gap-1.5">
                        <span className={opsLabelClass}>
                            Reorder point: suggest when stock is at or below (
                            {unit})
                        </span>
                        <input
                            className={opsInputClass}
                            inputMode="decimal"
                            value={reorder}
                            onChange={(event) => setReorder(event.target.value)}
                            placeholder="2"
                        />
                    </label>
                )}
                <div className="flex flex-col gap-1 rounded-xl bg-[#fbf6e9] p-3">
                    <span className={opsLabelClass}>
                        What Pamamalengke will do
                    </span>
                    <span className="text-sm leading-snug font-bold">
                        {ruleText[0]}
                    </span>
                    <span className="text-xs leading-5 text-[#666]">
                        {ruleText[1]}
                    </span>
                    {ingredient?.recommendation && (
                        <span className="pt-1 text-xs leading-5 text-[#444]">
                            Now at {operations.branch?.code}:{' '}
                            {ingredient.recommendation.kind === 'buy'
                                ? `buy ${ingredient.recommendation.units} ${unitLabel(ingredient.purchase_unit?.name ?? '', ingredient.recommendation.units)}.`
                                : ingredient.recommendation.reason}
                        </span>
                    )}
                </div>
            </div>
        </OperationsDialog>
    );
}
