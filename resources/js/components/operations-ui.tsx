import { Head, Link } from '@inertiajs/react';
import {
    Banknote,
    Check,
    ChevronDown,
    Citrus,
    Coffee,
    CookingPot,
    CupSoda,
    Droplet,
    Egg,
    GlassWater,
    Info,
    Leaf,
    Milk,
    Minus,
    Package,
    Plus,
    Settings,
    Soup,
    UtensilsCrossed,
    ChartColumn,
} from 'lucide-react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { OwnerPage } from '@/components/owner-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useReportsRealtimeRefresh } from '@/hooks/use-reports-realtime-refresh';
import {
    divideProfit,
    formatDelta,
    formatPeso,
    formatQuantity,
    planQuery,
    stockPercent,
    unitLabel,
} from '@/lib/operations';
import operationsRoutes from '@/routes/operations';
import type {
    EarlierPurchase,
    IngredientMovementGroup,
    ManualEntry,
    OperationsContext,
    OperationsFigures,
    OperationsIngredient,
    OperationsPageKey,
    OperationsSummaryProps,
} from '@/types/operations';

export const opsCardClass =
    'flex min-w-0 flex-col gap-2.5 rounded-[14px] border border-[#e5e5e5] bg-white p-3 md:gap-3 md:rounded-2xl md:p-4';
export const opsLabelClass =
    'text-[10px] font-semibold tracking-[0.07em] text-[#767676] uppercase';
export const opsButtonClass =
    'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-[10px] border border-[#d8d8d8] bg-white px-3 text-[12.5px] font-semibold text-[#111] transition hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-45';
export const opsPrimaryClass =
    'inline-flex min-h-11 items-center justify-center gap-1.5 rounded-[10px] border border-[#111] bg-[#111] px-4 text-[13px] font-semibold text-white transition hover:bg-neutral-800 focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:ring-offset-2 focus-visible:outline-none disabled:cursor-not-allowed disabled:opacity-45';
export const opsInputClass =
    'h-11 w-full min-w-0 rounded-[10px] border border-[#d8d8d8] bg-white px-3 text-base text-[#111] outline-none focus-visible:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111]/15 sm:text-[13.5px]';

export const OPERATIONS_PAGES: {
    key: OperationsPageKey;
    label: string;
    short: string;
}[] = [
    { key: 'plans', label: 'Pamalengke Plans', short: 'Plans' },
    { key: 'overview', label: 'Overview', short: 'Overview' },
    { key: 'ingredients', label: 'Ingredients', short: 'Ingredients' },
    { key: 'recipes', label: 'Recipes', short: 'Recipes' },
    { key: 'stock', label: 'Ingredient Stock', short: 'Stock' },
    { key: 'pamamalengke', label: 'Pamamalengke', short: 'Pamamalengke' },
    { key: 'purchases', label: 'Purchases', short: 'Purchases' },
];

/** The Wayfinder route of an Operations page, keeping the active Plan in the URL. */
export function operationsHref(
    page: OperationsPageKey,
    planId?: string | null,
) {
    return operationsRoutes[page](planQuery(planId));
}

const INGREDIENT_ICONS = {
    lemon: Citrus,
    bottle: Milk,
    drop: Droplet,
    cup: CupSoda,
    tea: Coffee,
    straw: GlassWater,
    leaf: Leaf,
    egg: Egg,
    bowl: Soup,
    box: Package,
} as const;

const PLAN_ICONS = {
    glass: GlassWater,
    meal: UtensilsCrossed,
    bowl: Soup,
    pot: CookingPot,
    box: Package,
} as const;

export const INGREDIENT_ICON_NAMES = Object.keys(INGREDIENT_ICONS);
export const PLAN_ICON_NAMES = Object.keys(PLAN_ICONS);

export function IngredientIcon({
    icon,
    size = 36,
}: {
    icon: string;
    size?: number;
}) {
    const Icon =
        INGREDIENT_ICONS[icon as keyof typeof INGREDIENT_ICONS] ?? Package;

    return (
        <span
            aria-hidden="true"
            style={{ width: size, height: size }}
            className="inline-flex shrink-0 items-center justify-center rounded-[10px] border border-[#efefef] bg-[#f7f7f7] text-[#111]"
        >
            <Icon className="size-[17px]" />
        </span>
    );
}

export function PlanIcon({
    icon,
    className = 'size-[17px]',
}: {
    icon: string;
    className?: string;
}) {
    const Icon = PLAN_ICONS[icon as keyof typeof PLAN_ICONS] ?? Package;

    return <Icon aria-hidden="true" className={className} />;
}

export type ChipTone =
    | 'dark'
    | 'gold'
    | 'amber'
    | 'red'
    | 'green'
    | 'plain'
    | 'neutral'
    | 'outline';

const CHIP_TONES: Record<ChipTone, string> = {
    dark: 'border-[#111] bg-[#111] text-white',
    gold: 'border-[#ead7a4] bg-[#fbf6e9] text-[#7a5710]',
    amber: 'border-amber-200 bg-amber-50 text-amber-800',
    red: 'border-red-200 bg-red-50 text-red-800',
    green: 'border-emerald-200 bg-emerald-50 text-emerald-800',
    plain: 'border-[#e5e5e5] bg-[#f2f2f2] text-[#333]',
    neutral: 'border-[#e5e5e5] bg-[#f2f2f2] text-[#333]',
    outline: 'border-[#d8d8d8] bg-white text-[#666]',
};

export function Chip({
    tone,
    children,
}: {
    tone: ChipTone;
    children: ReactNode;
}) {
    return (
        <span
            className={`inline-flex h-[22px] w-fit shrink-0 items-center rounded-full border px-2 text-[10px] font-semibold tracking-[0.05em] whitespace-nowrap uppercase ${CHIP_TONES[tone]}`}
        >
            {children}
        </span>
    );
}

const STATUS_BAR: Record<string, string> = {
    red: 'bg-[#b91c1c]',
    amber: 'bg-[#b45309]',
    green: 'bg-[#15803d]',
    neutral: 'bg-[#111]',
    outline: 'bg-[#949494]',
};

export function StockBar({ ingredient }: { ingredient: OperationsIngredient }) {
    if (!ingredient.stock) {
        return null;
    }
    const percent = stockPercent(ingredient.stock.current, ingredient.target);
    const tick =
        ingredient.rule === 'reorder' && ingredient.reorder_point !== null
            ? stockPercent(ingredient.reorder_point, ingredient.target)
            : null;

    return (
        <span
            aria-hidden="true"
            className="relative block h-1.5 rounded-full bg-[#efefef]"
        >
            <span
                className={`absolute inset-y-0 left-0 rounded-full ${STATUS_BAR[ingredient.status?.tone ?? 'neutral']}`}
                style={{ width: `${percent.toFixed(1)}%` }}
            />
            {tick !== null && (
                <span
                    className="absolute -inset-y-[3px] w-0.5 rounded bg-[#111]"
                    style={{
                        left: `calc(${Math.min(100, tick).toFixed(1)}% - 1px)`,
                    }}
                />
            )}
        </span>
    );
}

export function StatusChip({
    ingredient,
}: {
    ingredient: OperationsIngredient;
}) {
    if (!ingredient.status) {
        return null;
    }

    return <Chip tone={ingredient.status.tone}>{ingredient.status.label}</Chip>;
}

export function purchaseUnitLabel(ingredient: OperationsIngredient): string {
    const unit = ingredient.purchase_unit;
    if (!unit) {
        return 'Not set';
    }
    if (unit.size === '1' && unit.name === ingredient.base_unit) {
        return `1 ${ingredient.base_unit}`;
    }

    return `1 ${unit.name} = ${formatQuantity(unit.size, ingredient.base_unit)}`;
}

export function formatQuantityOrDash(
    display: string | null | undefined,
    unit: string,
): string {
    return display == null ? '—' : formatQuantity(display, unit);
}

export function EmptyState({
    title,
    body,
    action,
}: {
    title: string;
    body?: string;
    action?: ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2 rounded-2xl border border-dashed border-[#d8d8d8] bg-white px-4 py-10 text-center">
            <p className="text-[15px] font-semibold">{title}</p>
            {body && (
                <p className="max-w-[46ch] text-[12.5px] leading-5 text-[#767676]">
                    {body}
                </p>
            )}
            {action}
        </div>
    );
}

export function BranchRequired({ what }: { what: string }) {
    return (
        <EmptyState
            title="Choose a branch"
            body={`${what} is physical, branch-specific stock. Choose one Branch from the header; All Branches never shows an aggregated quantity as actionable stock.`}
        />
    );
}

/**
 * Operations page frame inside the existing Owner/Super Admin shell: page heading, the URL-addressable Active Plan
 * switcher, the Branch scope and (below the desktop sidebar width) a compact Operations sub-navigation.
 */
export function OperationsShell({
    operations,
    title,
    description,
    action,
    badge,
    children,
}: {
    operations: OperationsContext;
    title: string;
    description: string;
    action?: ReactNode;
    badge?: number;
    children: ReactNode;
}) {
    const [howOpen, setHowOpen] = useState(false);
    const active = operations.plans.find(
        (plan) => plan.id === operations.active_plan_id,
    );
    const scoped = operations.page !== 'plans';
    useReportsRealtimeRefresh(
        ['operations', ...liveProps[operations.page]],
        operations.branch?.id ?? null,
    );

    return (
        <>
            <Head title={`${title} · Operations`} />
            <OwnerPage title={title} description={description} action={action}>
                <div className="flex flex-wrap items-center gap-2">
                    {scoped && active && (
                        <DropdownMenu>
                            <DropdownMenuTrigger asChild>
                                <button
                                    type="button"
                                    aria-label={`Active plan: ${active.name}. Switch plan`}
                                    className="flex min-h-12 max-w-full min-w-0 items-center gap-2.5 rounded-xl border border-[#d8d8d8] bg-white py-1.5 pr-3 pl-1.5 text-left hover:border-[#111] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                                >
                                    <span className="flex size-9 shrink-0 items-center justify-center rounded-[10px] bg-[#111] text-white">
                                        <PlanIcon icon={active.icon} />
                                    </span>
                                    <span className="flex min-w-0 flex-col leading-tight">
                                        <span className="text-[9.5px] font-semibold tracking-[0.1em] text-[#8a8a8a] uppercase">
                                            Active plan
                                        </span>
                                        <span className="truncate text-sm font-bold">
                                            {active.name} plan
                                        </span>
                                    </span>
                                    <ChevronDown className="size-4 shrink-0 text-[#767676]" />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                align="start"
                                className="owner-surface w-[min(320px,calc(100vw-24px))] rounded-xl p-1.5"
                            >
                                <DropdownMenuLabel className="text-[10px] font-semibold tracking-[0.08em] text-[#8a8a8a] uppercase">
                                    Switch plan
                                </DropdownMenuLabel>
                                {operations.plans.map((plan) => (
                                    <DropdownMenuItem key={plan.id} asChild>
                                        <Link
                                            href={operationsHref(
                                                operations.page,
                                                plan.id,
                                            )}
                                            className="flex min-h-12 items-center gap-2.5"
                                        >
                                            <span
                                                className={`flex size-8 shrink-0 items-center justify-center rounded-[9px] ${plan.id === active.id ? 'bg-[#111] text-white' : 'bg-[#f2f2f2]'}`}
                                            >
                                                <PlanIcon
                                                    icon={plan.icon}
                                                    className="size-4"
                                                />
                                            </span>
                                            <span className="flex min-w-0 flex-1 flex-col">
                                                <span className="truncate text-[13.5px] font-semibold">
                                                    {plan.name} plan
                                                </span>
                                                <span className="text-[11px] text-[#767676]">
                                                    {plan.product_count}{' '}
                                                    products ·{' '}
                                                    {plan.ingredient_count}{' '}
                                                    ingredients
                                                </span>
                                            </span>
                                            {plan.id === active.id && (
                                                <Check
                                                    className="size-4"
                                                    aria-label="Active"
                                                />
                                            )}
                                        </Link>
                                    </DropdownMenuItem>
                                ))}
                                <DropdownMenuSeparator />
                                <DropdownMenuItem asChild>
                                    <Link
                                        href={operationsHref('plans')}
                                        className="min-h-11"
                                    >
                                        <Settings className="size-4" /> Manage
                                        plans
                                    </Link>
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    )}
                    <p className="min-w-0 flex-[1_1_180px] text-[11.5px] leading-5 text-[#767676]">
                        {operations.branch
                            ? `${operations.branch.name} (${operations.branch.code}) branch stock. Plans only change the view; stock stays one record per ingredient.`
                            : 'All Branches: read-only analytics. Choose one Branch in the header to see or change physical ingredient stock.'}
                    </p>
                    <button
                        type="button"
                        onClick={() => setHowOpen(true)}
                        className={opsButtonClass}
                    >
                        <Info className="size-4" />
                        <span>How this works</span>
                    </button>
                </div>

                <nav
                    aria-label="Operations pages"
                    className="owner-hide-scrollbar -mx-0.5 flex gap-1.5 overflow-x-auto px-0.5 min-[1180px]:hidden"
                >
                    {OPERATIONS_PAGES.map((item) => {
                        const current = item.key === operations.page;

                        return (
                            <Link
                                key={item.key}
                                href={operationsHref(
                                    item.key,
                                    operations.active_plan_id,
                                )}
                                aria-current={current ? 'page' : undefined}
                                className={`inline-flex h-10 shrink-0 items-center gap-1.5 rounded-[10px] border px-3 text-[12.5px] font-semibold whitespace-nowrap focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${current ? 'border-[#111] bg-[#111] text-white' : 'border-[#e5e5e5] bg-white text-[#111]'}`}
                            >
                                {item.short}
                                {item.key === 'pamamalengke' && badge ? (
                                    <span
                                        className={`inline-flex h-[18px] min-w-[18px] items-center justify-center rounded-full px-1 text-[10px] font-bold ${current ? 'bg-[#c8962e] text-[#111]' : 'bg-[#fbf6e9] text-[#7a5710]'}`}
                                    >
                                        {badge}
                                    </span>
                                ) : null}
                            </Link>
                        );
                    })}
                </nav>

                {children}
            </OwnerPage>
            <HowItWorksDialog
                open={howOpen}
                onClose={() => setHowOpen(false)}
                planId={operations.active_plan_id}
            />
        </>
    );
}

/** Props each page partially reloads when a `reports.changed` signal arrives for its Branch scope. */
const liveProps: Record<OperationsPageKey, string[]> = {
    plans: ['cards', 'summary', 'shared'],
    overview: [
        'figures',
        'ingredients',
        'market',
        'consumption',
        'movements',
        'summary',
        'earlier',
    ],
    ingredients: ['ingredients'],
    recipes: ['ingredients'],
    stock: ['ingredients', 'movements'],
    pamamalengke: ['ingredients', 'market', 'summary', 'earlier'],
    purchases: ['stats', 'purchases'],
};

export function OperationsDialog({
    open,
    onClose,
    kicker,
    title,
    description,
    children,
    footer,
    busy = false,
}: {
    open: boolean;
    onClose: () => void;
    kicker: string;
    title: string;
    description?: string;
    children: ReactNode;
    footer?: ReactNode;
    busy?: boolean;
}) {
    return (
        <Dialog
            open={open}
            onOpenChange={(next) => {
                if (!next && !busy) {
                    onClose();
                }
            }}
        >
            <DialogContent className="owner-surface top-auto bottom-0 flex max-h-[92dvh] w-full max-w-none translate-y-0 flex-col gap-0 overflow-hidden rounded-t-[20px] rounded-b-none border-[#e5e5e5] bg-white p-0 text-neutral-950 sm:top-1/2 sm:bottom-auto sm:max-w-[560px] sm:-translate-y-1/2 sm:rounded-[18px] [&>button]:top-2 [&>button]:right-2 [&>button]:flex [&>button]:min-h-11 [&>button]:min-w-11 [&>button]:items-center [&>button]:justify-center">
                <DialogHeader className="shrink-0 gap-0.5 border-b border-neutral-200 px-4 py-3 pr-12 text-left">
                    <p className="text-[10.5px] font-semibold tracking-[0.09em] text-neutral-500 uppercase">
                        {kicker}
                    </p>
                    <DialogTitle className="text-[16px] font-bold wrap-anywhere">
                        {title}
                    </DialogTitle>
                    <DialogDescription
                        className={
                            description ? 'text-[12px] text-[#666]' : 'sr-only'
                        }
                    >
                        {description ?? title}
                    </DialogDescription>
                </DialogHeader>
                <div className="min-h-0 flex-1 overflow-y-auto p-4">
                    {children}
                </div>
                {footer && (
                    <div className="shrink-0 border-t border-neutral-200 px-4 pt-3 pb-[max(12px,env(safe-area-inset-bottom))]">
                        {footer}
                    </div>
                )}
            </DialogContent>
        </Dialog>
    );
}

export function Segmented<T extends string>({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: T;
    options: { value: T; label: ReactNode }[];
    onChange: (value: T) => void;
}) {
    return (
        <div
            role="group"
            aria-label={label}
            className="flex min-w-0 gap-[3px] rounded-[11px] bg-[#f2f2f2] p-[3px]"
        >
            {options.map((option) => (
                <button
                    key={option.value}
                    type="button"
                    aria-pressed={value === option.value}
                    onClick={() => onChange(option.value)}
                    className={`inline-flex min-h-10 flex-1 items-center justify-center gap-1.5 rounded-[9px] px-3 text-[13px] font-semibold whitespace-nowrap focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none ${value === option.value ? 'bg-[#111] text-white' : 'text-[#666] hover:bg-white/70'}`}
                >
                    {option.label}
                </button>
            ))}
        </div>
    );
}

const MOVEMENT_TONE: Record<IngredientMovementGroup['type'], ChipTone> = {
    opening_balance: 'outline',
    sale_consumption: 'plain',
    order_edit_adjustment: 'amber',
    void_restoration: 'red',
    purchase_restock: 'green',
    wastage: 'amber',
    count_correction: 'outline',
};

const timeFormat = new Intl.DateTimeFormat('en-PH', {
    hour: '2-digit',
    minute: '2-digit',
    hour12: false,
    timeZone: 'Asia/Manila',
});

export function manilaTime(iso: string | null): string {
    return iso ? timeFormat.format(new Date(iso)) : '—';
}

/** Append-only movement history, newest first. Other Plans' lines on shared ingredients are summarized. */
export function MovementList({
    movements,
    planId,
    empty = 'No ingredient movements yet today.',
}: {
    movements: IngredientMovementGroup[];
    planId: string | null;
    empty?: string;
}) {
    if (movements.length === 0) {
        return <p className="text-[12.5px] text-[#767676]">{empty}</p>;
    }

    return (
        <ul className="flex flex-col">
            {movements.map((movement) => {
                const mine = movement.lines.filter((line) => line.mine);
                const others = movement.lines.length - mine.length;
                const title = [
                    movement.label,
                    movement.order?.number
                        ? `Order #${movement.order.number}`
                        : null,
                    movement.products.join(', ') || null,
                ]
                    .filter(Boolean)
                    .join(' · ');

                return (
                    <li
                        key={movement.id}
                        className="flex items-start gap-2.5 border-t border-[#f2f2f2] py-2.5 first:border-t-0"
                    >
                        <span className="w-10 shrink-0 pt-0.5 text-xs font-semibold text-[#666] tabular-nums">
                            {manilaTime(movement.created_at)}
                        </span>
                        <span className="flex min-w-0 flex-1 flex-col gap-1">
                            <span className="flex flex-wrap items-center gap-1.5">
                                <Chip tone={MOVEMENT_TONE[movement.type]}>
                                    {movement.label}
                                </Chip>
                                {movement.plan &&
                                    movement.plan.id !== planId && (
                                        <Chip tone="outline">
                                            {movement.plan.name} plan
                                        </Chip>
                                    )}
                            </span>
                            <span className="text-[13px] leading-snug font-semibold wrap-anywhere">
                                {title}
                            </span>
                            <span className="text-xs leading-5 wrap-anywhere text-[#555] tabular-nums">
                                {mine
                                    .map((line) =>
                                        `${line.name} ${formatDelta(line.delta)} ${line.unit ? unitLabel(line.unit, 2) : ''}`.trim(),
                                    )
                                    .join(' · ') || 'No lines for this plan.'}
                                {others > 0 &&
                                    ` · ${others} more line${others === 1 ? '' : 's'} for other plans`}
                            </span>
                            <span className="text-[11px] wrap-anywhere text-[#8a8a8a]">
                                {[movement.reason, movement.by]
                                    .filter(Boolean)
                                    .join(' · ')}
                            </span>
                        </span>
                    </li>
                );
            })}
        </ul>
    );
}

function KeyValue({
    label,
    value,
    sub,
    kind = 'line',
}: {
    label: string;
    value: string;
    sub?: string;
    kind?: 'line' | 'mid' | 'total';
}) {
    return (
        <div
            className={`flex items-center gap-2.5 ${kind === 'total' ? 'mt-0.5 border-t-[1.5px] border-[#111] pt-2.5 pb-0.5' : kind === 'mid' ? 'border-y border-t-[#c9c9c9] border-b-[#f2f2f2] py-2' : 'border-b border-[#f2f2f2] py-2'}`}
        >
            <span className="flex min-w-0 flex-1 flex-col gap-px">
                <span
                    className={
                        kind === 'line'
                            ? 'text-[13px] font-medium'
                            : 'text-[13.5px] font-bold'
                    }
                >
                    {label}
                </span>
                {sub && (
                    <span className="text-[11px] leading-4 text-[#8a8a8a]">
                        {sub}
                    </span>
                )}
            </span>
            <span
                className={`shrink-0 font-bold whitespace-nowrap tabular-nums ${kind === 'total' ? 'text-lg' : kind === 'mid' ? 'text-[15px]' : 'text-[13.5px] font-semibold'}`}
            >
                {value}
            </span>
        </div>
    );
}

/**
 * View summary: the Pamamalengke list estimate and today's Sales & profit, all computed on the server. The Cash view
 * is never labelled profit, Store-wide expenses appear only in the All plans (business) scope, and the Profit divider
 * is a calculator that writes nothing.
 */
export function SummaryDialog({
    open,
    onClose,
    planName,
    summary,
    auto,
    manual,
    earlier,
    shopping,
    initialTab = 'market',
}: {
    open: boolean;
    onClose: () => void;
    planName: string;
    summary: OperationsSummaryProps;
    auto:
        | { name: string; quantity: string; estimate_cents: number | null }[]
        | null;
    manual: ManualEntry[];
    earlier: EarlierPurchase[];
    shopping?: {
        estimate_cents: number;
        actual_cents: number;
        checked: number;
        total: number;
    } | null;
    initialTab?: 'market' | 'profit';
}) {
    const [tab, setTab] = useState<'market' | 'profit'>(initialTab);
    const [scope, setScope] = useState<'plan' | 'all'>('plan');
    const [split, setSplit] = useState<'1' | '2' | '3' | 'custom'>('2');
    const [custom, setCustom] = useState('4');
    const figures: OperationsFigures | null =
        scope === 'plan' ? summary.plan : summary.business;
    const autoEstimate = (auto ?? []).reduce(
        (sum, item) => sum + (item.estimate_cents ?? 0),
        0,
    );
    const manualEstimate = manual.reduce(
        (sum, item) => sum + (item.estimate_cents ?? 0),
        0,
    );
    const unknown =
        (auto ?? []).filter((item) => item.estimate_cents === null).length +
        manual.filter((item) => item.estimate_cents === null).length;
    const shares = divideProfit(
        figures?.operating_profit_cents ?? 0,
        split === 'custom' ? Number(custom) : Number(split),
    );

    return (
        <OperationsDialog
            open={open}
            onClose={onClose}
            kicker="View summary"
            title={`${planName} plan · Today`}
            description={`Business date ${summary.business_date}. Values are calculated on the server from recorded sales, recipes and purchases.`}
            footer={
                <button
                    type="button"
                    className={`${opsPrimaryClass} w-full`}
                    onClick={onClose}
                >
                    Done
                </button>
            }
        >
            <div className="flex flex-col gap-3.5">
                <Segmented
                    label="Summary"
                    value={tab}
                    onChange={setTab}
                    options={[
                        { value: 'market', label: 'Pamamalengke' },
                        { value: 'profit', label: 'Sales & profit' },
                    ]}
                />
                {tab === 'market' ? (
                    <div className="flex flex-col gap-3.5">
                        {auto === null ? (
                            <p className="text-[12.5px] text-[#767676]">
                                Choose one Branch to see suggestions. They come
                                from that branch's stock.
                            </p>
                        ) : (
                            <>
                                {auto.length > 0 && (
                                    <SummaryList
                                        tone="auto"
                                        rows={auto.map((item) => ({
                                            name: item.name,
                                            qty: item.quantity,
                                            estimate: item.estimate_cents,
                                        }))}
                                    />
                                )}
                                {manual.length > 0 && (
                                    <SummaryList
                                        tone="manual"
                                        rows={manual.map((item) => ({
                                            name: item.name,
                                            qty: formatQuantity(
                                                item.quantity,
                                                item.unit,
                                            ),
                                            estimate: item.estimate_cents,
                                        }))}
                                    />
                                )}
                                {auto.length === 0 && manual.length === 0 && (
                                    <p className="text-[12.5px] text-[#767676]">
                                        Nothing is on the list for the next run.
                                    </p>
                                )}
                            </>
                        )}
                        <div className="flex items-center gap-2.5 rounded-xl bg-[#fbf6e9] p-3">
                            <span className="flex-1 text-[13px] font-bold">
                                Estimated market cost
                            </span>
                            <span className="text-[19px] font-bold tabular-nums">
                                {formatPeso(autoEstimate + manualEstimate)}
                            </span>
                        </div>
                        {unknown > 0 && (
                            <p className="text-[11.5px] text-[#b45309]">
                                {unknown} item
                                {unknown === 1 ? ' has' : 's have'} no known
                                cost, so the estimate is incomplete.
                            </p>
                        )}
                        {shopping && shopping.checked > 0 && (
                            <div className="grid grid-cols-2 gap-2">
                                <div className="flex flex-col gap-1 rounded-xl bg-[#f7f7f7] p-3">
                                    <span className={opsLabelClass}>
                                        Estimated for bought items
                                    </span>
                                    <span className="text-lg font-bold tabular-nums">
                                        {formatPeso(shopping.estimate_cents)}
                                    </span>
                                </div>
                                <div className="flex flex-col gap-1 rounded-xl border border-[#111] p-3">
                                    <span className={opsLabelClass}>
                                        Actual so far
                                    </span>
                                    <span className="text-lg font-bold tabular-nums">
                                        {formatPeso(shopping.actual_cents)}
                                    </span>
                                </div>
                                <p className="col-span-2 text-[11.5px] text-[#767676] tabular-nums">
                                    {shopping.checked} of {shopping.total} items
                                    checked off
                                </p>
                            </div>
                        )}
                        {earlier.length > 0 && (
                            <div className="flex flex-col">
                                <span className={`${opsLabelClass} pb-1`}>
                                    Already bought today
                                </span>
                                {earlier.map((run) => (
                                    <div
                                        key={run.id}
                                        className="flex items-center gap-2.5 border-b border-[#f2f2f2] py-2"
                                    >
                                        <span className="flex min-w-0 flex-1 flex-col">
                                            <span className="text-[13px] font-semibold">
                                                Pamamalengke run ·{' '}
                                                {manilaTime(run.created_at)}
                                            </span>
                                            <span className="text-[11.5px] text-[#767676]">
                                                {run.items} items ·{' '}
                                                {run.expense_reference}
                                            </span>
                                        </span>
                                        <span className="flex flex-col items-end tabular-nums">
                                            <span className="text-[11.5px] text-[#767676]">
                                                Est.{' '}
                                                {run.estimate_cents === null
                                                    ? 'incomplete'
                                                    : formatPeso(
                                                          run.estimate_cents,
                                                      )}
                                            </span>
                                            <span className="text-[13px] font-bold">
                                                Actual{' '}
                                                {formatPeso(run.actual_cents)}
                                            </span>
                                        </span>
                                    </div>
                                ))}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="flex flex-col gap-3.5">
                        <Segmented
                            label="Summary scope"
                            value={scope}
                            onChange={setScope}
                            options={[
                                { value: 'plan', label: `${planName} plan` },
                                { value: 'all', label: 'All plans' },
                            ]}
                        />
                        {figures === null ? (
                            <p className="text-[12.5px] text-[#767676]">
                                No sales recorded for this plan today.
                            </p>
                        ) : (
                            <>
                                <section
                                    aria-label="Cash view"
                                    className="flex flex-col gap-1 rounded-xl border border-[#e5e5e5] p-3"
                                >
                                    <span className="flex items-center gap-2 text-sm font-bold">
                                        <Banknote className="size-4" /> Cash
                                        view
                                    </span>
                                    <KeyValue
                                        label="Sales today"
                                        value={formatPeso(figures.sales_cents)}
                                        sub="Sales value, Pay Later included"
                                    />
                                    <KeyValue
                                        label="Pamamalengke today"
                                        value={`−${formatPeso(figures.pamamalengke_cents)}`}
                                        sub="Paid today, including stock still on the shelf"
                                    />
                                    {scope === 'all' && (
                                        <KeyValue
                                            label="Other store expenses"
                                            value={`−${formatPeso(figures.other_expenses_cents)}`}
                                            sub="Other Store Purchases / Expenses today"
                                        />
                                    )}
                                    <KeyValue
                                        label="Cash after purchases"
                                        value={formatPeso(
                                            figures.cash_after_cents,
                                        )}
                                        kind="total"
                                    />
                                    <p className="pt-1 text-[11.5px] leading-5 text-[#666]">
                                        This is the cash remaining after today's
                                        purchases and expenses. Purchased stock
                                        may still remain in inventory, so this
                                        is not true profit.
                                    </p>
                                </section>
                                <section
                                    aria-label="Profit view"
                                    className="flex flex-col gap-1 rounded-xl border border-[#e5e5e5] p-3"
                                >
                                    <span className="flex items-center gap-2 text-sm font-bold">
                                        <ChartColumn className="size-4" />{' '}
                                        Profit view{' '}
                                        <Chip tone="gold">Estimated</Chip>
                                    </span>
                                    <KeyValue
                                        label="Net sales"
                                        value={formatPeso(figures.sales_cents)}
                                    />
                                    <KeyValue
                                        label="Estimated ingredient COGS"
                                        value={`−${formatPeso(figures.cogs_cents)}`}
                                        sub={
                                            figures.uncosted_sales_cents > 0
                                                ? `${formatPeso(figures.uncosted_sales_cents)} of sales has no recipe cost and is not costed`
                                                : "What today's sales consumed, at the costs recorded when sold"
                                        }
                                    />
                                    <KeyValue
                                        label="Estimated gross profit"
                                        value={formatPeso(
                                            figures.gross_profit_cents,
                                        )}
                                        kind="mid"
                                    />
                                    <KeyValue
                                        label="Operating expenses"
                                        value={`−${formatPeso(figures.non_stock_cents + figures.other_expenses_cents)}`}
                                        sub={
                                            scope === 'all'
                                                ? 'Non-stock pamamalengke items and other store expenses'
                                                : 'Non-stock pamamalengke items bought for this plan today'
                                        }
                                    />
                                    <KeyValue
                                        label="Estimated operating profit"
                                        value={formatPeso(
                                            figures.operating_profit_cents,
                                        )}
                                        kind="total"
                                    />
                                    <p className="pt-1 text-[11.5px] leading-5 text-[#666]">
                                        {figures.incomplete
                                            ? 'Incomplete estimate: some sales have no recipe or cost, and are never treated as ₱0 cost. '
                                            : ''}
                                        {scope === 'plan'
                                            ? 'Store-wide expenses are not allocated to a plan. Switch to All plans to include them.'
                                            : 'All plans includes every sale and each store-wide expense once.'}
                                    </p>
                                </section>
                                <section
                                    aria-label="Divide estimated profit"
                                    className="flex flex-col gap-2.5 rounded-xl bg-[#f7f7f7] p-3"
                                >
                                    <span className="flex flex-col gap-0.5">
                                        <span className="text-sm font-bold">
                                            Divide estimated profit
                                        </span>
                                        <span className="text-xs text-[#666] tabular-nums">
                                            Estimated operating profit ·{' '}
                                            {formatPeso(
                                                figures.operating_profit_cents,
                                            )}
                                        </span>
                                    </span>
                                    <Segmented
                                        label="Number of shares"
                                        value={split}
                                        onChange={setSplit}
                                        options={[
                                            { value: '1', label: '1' },
                                            { value: '2', label: '2' },
                                            { value: '3', label: '3' },
                                            {
                                                value: 'custom',
                                                label: 'Custom',
                                            },
                                        ]}
                                    />
                                    {split === 'custom' && (
                                        <div className="flex items-center gap-2">
                                            <label
                                                htmlFor="profit-shares"
                                                className="flex-1 text-[12.5px] font-semibold"
                                            >
                                                Number of shares
                                            </label>
                                            <button
                                                type="button"
                                                aria-label="Fewer shares"
                                                className={`${opsButtonClass} w-11 px-0`}
                                                onClick={() =>
                                                    setCustom(
                                                        String(
                                                            Math.max(
                                                                1,
                                                                (Number(
                                                                    custom,
                                                                ) || 1) - 1,
                                                            ),
                                                        ),
                                                    )
                                                }
                                            >
                                                <Minus className="size-4" />
                                            </button>
                                            <input
                                                id="profit-shares"
                                                type="number"
                                                inputMode="numeric"
                                                min={1}
                                                max={20}
                                                value={custom}
                                                onChange={(event) =>
                                                    setCustom(
                                                        event.target.value,
                                                    )
                                                }
                                                className={`${opsInputClass} w-16 text-center`}
                                            />
                                            <button
                                                type="button"
                                                aria-label="More shares"
                                                className={`${opsButtonClass} w-11 px-0`}
                                                onClick={() =>
                                                    setCustom(
                                                        String(
                                                            Math.min(
                                                                20,
                                                                (Number(
                                                                    custom,
                                                                ) || 1) + 1,
                                                            ),
                                                        ),
                                                    )
                                                }
                                            >
                                                <Plus className="size-4" />
                                            </button>
                                        </div>
                                    )}
                                    <div
                                        className={`grid gap-2 ${shares.shares === 1 ? 'grid-cols-1' : shares.shares === 3 ? 'grid-cols-3' : 'grid-cols-2'}`}
                                    >
                                        {shares.shares <= 6 ? (
                                            Array.from(
                                                { length: shares.shares },
                                                (_, index) => (
                                                    <div
                                                        key={index}
                                                        className="flex flex-col gap-1 rounded-[10px] border border-[#e5e5e5] bg-white p-2.5"
                                                    >
                                                        <span
                                                            className={
                                                                opsLabelClass
                                                            }
                                                        >
                                                            Share {index + 1}
                                                        </span>
                                                        <span className="text-[17px] font-bold tabular-nums">
                                                            {formatPeso(
                                                                shares.each,
                                                            )}
                                                        </span>
                                                    </div>
                                                ),
                                            )
                                        ) : (
                                            <div className="col-span-2 flex flex-col gap-1 rounded-[10px] border border-[#e5e5e5] bg-white p-2.5">
                                                <span className={opsLabelClass}>
                                                    {shares.shares} shares
                                                </span>
                                                <span className="text-[17px] font-bold tabular-nums">
                                                    {formatPeso(shares.each)}{' '}
                                                    each
                                                </span>
                                            </div>
                                        )}
                                    </div>
                                    <p className="text-[11.5px] leading-5 text-[#666]">
                                        {shares.remainder !== 0 &&
                                            `${formatPeso(Math.abs(shares.remainder))} cannot be split equally. `}
                                        A calculator only. Dividing here does
                                        not record an expense, payment or owner
                                        withdrawal, and changes no financial
                                        records.
                                    </p>
                                </section>
                            </>
                        )}
                    </div>
                )}
            </div>
        </OperationsDialog>
    );
}

function SummaryList({
    tone,
    rows,
}: {
    tone: 'auto' | 'manual';
    rows: { name: string; qty: string; estimate: number | null }[];
}) {
    return (
        <div className="flex flex-col">
            <span className="flex items-center gap-2 pb-1">
                <span
                    className={`size-2 rounded-full ${tone === 'auto' ? 'bg-[#c8962e]' : 'bg-[#111]'}`}
                />
                <span className={opsLabelClass}>
                    {tone === 'auto' ? 'Auto' : 'Manual'}
                </span>
            </span>
            {rows.map((row) => (
                <div
                    key={`${row.name}-${row.qty}`}
                    className="flex items-center gap-2.5 border-b border-[#f2f2f2] py-2"
                >
                    <span className="flex min-w-0 flex-1 flex-col">
                        <span className="text-[13px] font-semibold">
                            {row.name}
                        </span>
                        <span className="text-[11.5px] text-[#767676] tabular-nums">
                            {row.qty}
                        </span>
                    </span>
                    <span
                        className={`text-[13px] font-semibold whitespace-nowrap tabular-nums ${row.estimate === null ? 'text-[#b45309]' : ''}`}
                    >
                        {row.estimate === null
                            ? 'Cost unknown'
                            : formatPeso(row.estimate)}
                    </span>
                </div>
            ))}
        </div>
    );
}

const HOW_STEPS: { title: string; page: OperationsPageKey; where: string }[] = [
    {
        title: 'Create or select a Pamalengke Plan',
        page: 'plans',
        where: 'Plans',
    },
    {
        title: 'Add and manage ingredients',
        page: 'ingredients',
        where: 'Ingredients',
    },
    {
        title: 'Attach recipes to existing products',
        page: 'recipes',
        where: 'Recipes',
    },
    {
        title: 'Product sales consume ingredient stock',
        page: 'stock',
        where: 'Stock',
    },
    {
        title: 'An edit creates only the ingredient delta',
        page: 'stock',
        where: 'Stock',
    },
    {
        title: 'A void restores ingredient usage, once',
        page: 'stock',
        where: 'Stock',
    },
    {
        title: 'Stock is compared with replenishment rules',
        page: 'ingredients',
        where: 'Ingredients',
    },
    {
        title: 'Pamamalengke suggestions are generated',
        page: 'pamamalengke',
        where: 'Pamamalengke',
    },
    {
        title: 'The owner shops using the checklist',
        page: 'pamamalengke',
        where: 'Checklist',
    },
    {
        title: 'The actual purchase is confirmed',
        page: 'pamamalengke',
        where: 'Checklist',
    },
    {
        title: 'Ingredient inventory is restocked',
        page: 'stock',
        where: 'Stock',
    },
    {
        title: 'The existing Store Expense records the purchase',
        page: 'purchases',
        where: 'Purchases',
    },
    {
        title: 'Sales, COGS and profit insights update',
        page: 'overview',
        where: 'Overview',
    },
];

export function HowItWorksDialog({
    open,
    onClose,
    planId,
}: {
    open: boolean;
    onClose: () => void;
    planId: string | null;
}) {
    return (
        <OperationsDialog
            open={open}
            onClose={onClose}
            kicker="Operations"
            title="How this works"
            description="Each step feeds the next. Tap a step to open it."
        >
            <ol className="flex flex-col">
                {HOW_STEPS.map((step, index) => (
                    <li key={step.title}>
                        <Link
                            href={operationsHref(step.page, planId)}
                            onClick={onClose}
                            className="flex min-h-12 items-center gap-3 rounded-lg px-1 py-1.5 hover:bg-[#fafafa] focus-visible:ring-2 focus-visible:ring-[#111] focus-visible:outline-none"
                        >
                            <span
                                className={`flex size-7 shrink-0 items-center justify-center rounded-full text-xs font-bold ${step.where === 'Pamamalengke' || step.where === 'Checklist' ? 'bg-[#c8962e] text-[#111]' : 'bg-[#111] text-white'}`}
                            >
                                {index + 1}
                            </span>
                            <span className="min-w-0 flex-1 text-[13px] leading-snug font-semibold">
                                {step.title}
                            </span>
                            <span className="shrink-0 text-[11px] font-semibold text-[#767676]">
                                {step.where}
                            </span>
                        </Link>
                    </li>
                ))}
            </ol>
        </OperationsDialog>
    );
}
