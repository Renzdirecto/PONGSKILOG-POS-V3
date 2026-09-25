import { Head, router } from '@inertiajs/react';
import {
    Activity,
    ArrowRight,
    ChevronLeft,
    ChevronRight,
    CirclePlus,
    CircleUserRound,
    Clock3,
    CreditCard,
    Database,
    FileClock,
    Filter,
    History,
    KeyRound,
    LogIn,
    Pencil,
    Search,
    ShieldBan,
    ShieldCheck,
    Store,
    X,
    type LucideIcon,
} from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import {
    OwnerPage,
    OwnerStatusBadge,
    ownerControlClass,
    ownerPanelClass,
    ownerSecondaryActionClass,
} from '@/components/owner-ui';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAuditRealtimeRefresh } from '@/hooks/use-audit-realtime-refresh';
import {
    auditActionLabel,
    auditActorName,
    titleCase,
} from '@/lib/audit-actions';
import { auditTrail } from '@/routes/workspaces';

type Option = {
    id: string | number;
    name: string;
    code?: string;
    email?: string;
};
type PageLink = { url: string | null; label: string; active: boolean };
type AuditValues = Record<string, unknown> | null;
type AuditLog = {
    id: string;
    created_at: string;
    branch: { id: string; name: string; code: string } | null;
    actor: {
        id: number;
        name: string;
        email: string;
        position?: string | null;
    } | null;
    module: string;
    action: string;
    auditable_type: string;
    auditable_id: string;
    before: AuditValues;
    after: AuditValues;
    metadata: AuditValues;
};
type Props = {
    logs: {
        data: AuditLog[];
        links: PageLink[];
        from: number | null;
        to: number | null;
        total: number;
    };
    filters: Record<string, string>;
    branches: Option[];
    users: Option[];
    modules: string[];
    actions: string[];
};
type ChangeRow = { field: string; before: string; after: string };

const dateTime = new Intl.DateTimeFormat('en-PH', {
    dateStyle: 'medium',
    timeStyle: 'short',
    timeZone: 'Asia/Manila',
});

const actionStyles: Record<
    string,
    {
        icon: LucideIcon;
        iconClass: string;
        badge: 'red' | 'green' | 'amber' | 'blue' | 'neutral';
    }
> = {
    'order.voided': {
        icon: ShieldBan,
        iconClass: 'bg-red-50 text-red-700 ring-red-100',
        badge: 'red',
    },
    'order.paid': {
        icon: CreditCard,
        iconClass: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        badge: 'green',
    },
    'void_pin.configured': {
        icon: KeyRound,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'blue',
    },
    'staff.created': {
        icon: CircleUserRound,
        iconClass: 'bg-blue-50 text-blue-700 ring-blue-100',
        badge: 'blue',
    },
    'staff.updated': {
        icon: Pencil,
        iconClass: 'bg-blue-50 text-blue-700 ring-blue-100',
        badge: 'blue',
    },
    'staff.role_changed': {
        icon: ShieldCheck,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'amber',
    },
    'staff.branch_access_changed': {
        icon: Store,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'amber',
    },
    'staff.deactivated': {
        icon: ShieldBan,
        iconClass: 'bg-red-50 text-red-700 ring-red-100',
        badge: 'red',
    },
    'staff.reactivated': {
        icon: CircleUserRound,
        iconClass: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
        badge: 'green',
    },
    'staff.password_reset': {
        icon: KeyRound,
        iconClass: 'bg-amber-50 text-amber-700 ring-amber-100',
        badge: 'amber',
    },
    'access.role_permissions_updated': {
        icon: ShieldCheck,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'amber',
    },
    'access.user_override_updated': {
        icon: ShieldCheck,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'amber',
    },
    'access.user_overrides_reset': {
        icon: ShieldCheck,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'blue',
    },
    'access.custom_role_created': {
        icon: ShieldCheck,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'blue',
    },
    'access.custom_role_updated': {
        icon: Pencil,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'blue',
    },
    'access.custom_role_permissions_updated': {
        icon: ShieldCheck,
        iconClass: 'bg-violet-50 text-violet-700 ring-violet-100',
        badge: 'amber',
    },
    'access.custom_role_archived': {
        icon: ShieldBan,
        iconClass: 'bg-neutral-100 text-neutral-700 ring-neutral-200',
        badge: 'neutral',
    },
};

export function auditChangeRows(
    before: AuditValues,
    after: AuditValues,
): ChangeRow[] {
    const keys = [
        ...new Set([...Object.keys(before ?? {}), ...Object.keys(after ?? {})]),
    ];

    return keys
        .filter(
            (key) =>
                JSON.stringify(before?.[key]) !== JSON.stringify(after?.[key]),
        )
        .map((key) => ({
            field: titleCase(key.replaceAll('_', ' ')),
            before: displayValue(before?.[key]),
            after: displayValue(after?.[key]),
        }));
}

export default function AuditTrail({
    logs,
    filters,
    branches,
    users,
    modules,
    actions,
}: Props) {
    const [selected, setSelected] = useState<AuditLog | null>(null);
    const [search, setSearch] = useState(filters.search ?? '');
    const realtimeProps = useMemo(() => ['logs', 'modules', 'actions'], []);
    const activeFilterCount = Object.values(filters).filter(Boolean).length;
    const visibleActors = new Set(
        logs.data.map((log) => log.actor?.id).filter(Boolean),
    ).size;
    const visibleBranches = new Set(
        logs.data.map((log) => log.branch?.id).filter(Boolean),
    ).size;

    useAuditRealtimeRefresh(realtimeProps);

    const apply = (next: Record<string, string>) => {
        const query = Object.fromEntries(
            Object.entries({ ...filters, ...next }).filter(
                ([, value]) => value,
            ),
        );
        router.get(auditTrail(), query, {
            preserveScroll: true,
            preserveState: true,
            replace: true,
        });
    };

    useEffect(() => {
        if (search === (filters.search ?? '')) {
            return;
        }

        const timer = window.setTimeout(() => apply({ search }), 350);

        return () => window.clearTimeout(timer);
    }, [search, filters.search]);

    const clear = () => {
        setSearch('');
        router.get(auditTrail(), {}, { preserveScroll: true, replace: true });
    };

    return (
        <>
            <Head title="Audit trail" />
            <OwnerPage
                title="Audit trail"
                description="A live, tamper-resistant record of important activity across every branch."
                action={
                    <OwnerStatusBadge tone="green">
                        <Activity className="mr-1 size-3" /> Live monitoring
                    </OwnerStatusBadge>
                }
            >
                <section className="grid grid-cols-2 gap-2 lg:grid-cols-4">
                    <SummaryCard
                        icon={FileClock}
                        label="Matching events"
                        value={String(logs.total)}
                        helper="All filtered records"
                    />
                    <SummaryCard
                        icon={Database}
                        label="Visible range"
                        value={logs.from ? `${logs.from}–${logs.to}` : '0'}
                        helper="Current page"
                    />
                    <SummaryCard
                        icon={CircleUserRound}
                        label="Active actors"
                        value={String(visibleActors)}
                        helper="On this page"
                    />
                    <SummaryCard
                        icon={Store}
                        label="Branches"
                        value={String(visibleBranches)}
                        helper="On this page"
                    />
                </section>

                <section className={`${ownerPanelClass} overflow-hidden`}>
                    <div className="border-b border-neutral-100 bg-neutral-50/60 px-4 py-3">
                        <div className="flex flex-wrap items-center justify-between gap-2">
                            <div className="flex items-center gap-2">
                                <span className="flex size-8 items-center justify-center rounded-lg bg-white text-neutral-600 ring-1 ring-neutral-200">
                                    <Filter className="size-4" />
                                </span>
                                <div>
                                    <h2 className="text-sm font-bold">
                                        Find activity
                                    </h2>
                                    <p className="text-[11px] text-neutral-500">
                                        Search updates automatically as you type
                                    </p>
                                </div>
                            </div>
                            {activeFilterCount > 0 ? (
                                <button
                                    type="button"
                                    onClick={clear}
                                    className="inline-flex min-h-9 items-center gap-1.5 rounded-lg px-2.5 text-xs font-semibold text-red-700 transition hover:bg-red-50"
                                >
                                    <X className="size-3.5" /> Clear{' '}
                                    {activeFilterCount} filter
                                    {activeFilterCount === 1 ? '' : 's'}
                                </button>
                            ) : (
                                <OwnerStatusBadge tone="outline">
                                    All activity
                                </OwnerStatusBadge>
                            )}
                        </div>
                    </div>
                    <div className="grid gap-2 p-3 min-[1320px]:grid-cols-[minmax(240px,2fr)_minmax(130px,1fr)_minmax(130px,1fr)_minmax(105px,.75fr)_minmax(150px,1.2fr)_135px] md:grid-cols-2 md:p-4 lg:grid-cols-3">
                        <label className="relative min-w-0">
                            <span className="sr-only">Search audit trail</span>
                            <Search className="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-neutral-400" />
                            <input
                                value={search}
                                onChange={(event) =>
                                    setSearch(event.target.value)
                                }
                                placeholder="Search action, staff, or record ID"
                                className={`${ownerControlClass} w-full pl-10`}
                            />
                        </label>
                        <div>
                            <Select
                                label="Branch"
                                value={filters.branch_id ?? ''}
                                options={branches.map((branch) => [
                                    String(branch.id),
                                    `${branch.name} · ${branch.code}`,
                                ])}
                                onChange={(branch_id) => apply({ branch_id })}
                            />
                        </div>
                        <div>
                            <Select
                                label="Actor"
                                value={filters.user_id ?? ''}
                                options={users.map((user) => [
                                    String(user.id),
                                    user.name,
                                ])}
                                onChange={(user_id) => apply({ user_id })}
                            />
                        </div>
                        <div>
                            <Select
                                label="Module"
                                value={filters.module ?? ''}
                                options={modules.map((module) => [
                                    module,
                                    moduleLabel(module),
                                ])}
                                onChange={(module) => apply({ module })}
                            />
                        </div>
                        <div>
                            <Select
                                label="Action"
                                value={filters.action ?? ''}
                                options={actions.map((action) => [
                                    action,
                                    auditActionLabel(action),
                                ])}
                                onChange={(action) => apply({ action })}
                            />
                        </div>
                        <label className="grid gap-1 text-[10px] font-semibold tracking-wide text-neutral-500 uppercase">
                            Date
                            <input
                                type="date"
                                value={filters.date ?? ''}
                                onChange={(event) =>
                                    apply({ date: event.target.value })
                                }
                                className={`${ownerControlClass} w-full px-2`}
                            />
                        </label>
                    </div>
                </section>

                <section className={`${ownerPanelClass} overflow-hidden`}>
                    <div className="flex items-center justify-between gap-3 border-b border-neutral-100 px-4 py-3.5">
                        <div>
                            <h2 className="text-sm font-bold">Activity feed</h2>
                            <p className="mt-0.5 text-xs text-neutral-500">
                                Newest event first · immutable records
                            </p>
                        </div>
                        <OwnerStatusBadge tone="neutral">
                            {logs.total} events
                        </OwnerStatusBadge>
                    </div>
                    {logs.data.length === 0 ? (
                        <EmptyState />
                    ) : (
                        <div>
                            {logs.data.map((log, index) => (
                                <AuditRow
                                    key={log.id}
                                    log={log}
                                    last={index === logs.data.length - 1}
                                    onSelect={setSelected}
                                />
                            ))}
                        </div>
                    )}
                    <Pagination links={logs.links} />
                </section>
            </OwnerPage>
            {selected && (
                <AuditDetail log={selected} onClose={() => setSelected(null)} />
            )}
        </>
    );
}

function SummaryCard({
    icon: Icon,
    label,
    value,
    helper,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
    helper: string;
}) {
    return (
        <div
            className={`${ownerPanelClass} flex min-w-0 items-center gap-3 p-3.5`}
        >
            <span className="flex size-10 shrink-0 items-center justify-center rounded-xl bg-neutral-950 text-white">
                <Icon className="size-4" />
            </span>
            <div className="min-w-0">
                <p className="truncate text-[10px] font-semibold tracking-wide text-neutral-500 uppercase">
                    {label}
                </p>
                <p className="text-lg font-bold tabular-nums">{value}</p>
                <p className="truncate text-[10px] text-neutral-400">
                    {helper}
                </p>
            </div>
        </div>
    );
}

function Select({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string;
    options: [string, string][];
    onChange: (value: string) => void;
}) {
    return (
        <label className="grid gap-1 text-[10px] font-semibold tracking-wide text-neutral-500 uppercase">
            {label}
            <select
                value={value}
                onChange={(event) => onChange(event.target.value)}
                className={`${ownerControlClass} w-full px-2`}
            >
                <option value="">All</option>
                {options.map(([optionValue, optionLabel]) => (
                    <option key={optionValue} value={optionValue}>
                        {optionLabel}
                    </option>
                ))}
            </select>
        </label>
    );
}

function AuditRow({
    log,
    last,
    onSelect,
}: {
    log: AuditLog;
    last: boolean;
    onSelect: (log: AuditLog) => void;
}) {
    const style = actionStyle(log.action);
    const Icon = style.icon;

    return (
        <button
            type="button"
            onClick={() => onSelect(log)}
            className="group relative flex w-full gap-3 px-4 py-4 text-left transition hover:bg-neutral-50 sm:gap-4 sm:px-5"
        >
            <span
                className={`relative z-10 flex size-10 shrink-0 items-center justify-center rounded-xl ring-1 ${style.iconClass}`}
            >
                <Icon className="size-4.5" />
            </span>
            {!last && (
                <span className="absolute top-12 bottom-0 left-[35px] w-px bg-neutral-200 sm:left-[39px]" />
            )}
            <span className="grid min-w-0 flex-1 gap-3 md:grid-cols-[minmax(210px,1.4fr)_minmax(150px,1fr)_minmax(140px,.8fr)_auto] md:items-center">
                <span className="min-w-0">
                    <span className="flex flex-wrap items-center gap-2">
                        <strong className="truncate text-sm">
                            {auditActionLabel(log.action)}
                        </strong>
                        <OwnerStatusBadge tone={style.badge}>
                            {moduleLabel(log.module)}
                        </OwnerStatusBadge>
                    </span>
                    <span className="mt-1 block truncate text-xs text-neutral-500">
                        {log.auditable_type} · {shortId(log.auditable_id)}
                    </span>
                </span>
                <span className="min-w-0">
                    <span className="block truncate text-xs font-semibold">
                        {auditActorName(log.actor)}
                    </span>
                    <span className="block truncate text-[11px] text-neutral-500">
                        {log.actor?.email ?? 'Automated activity'}
                    </span>
                </span>
                <span className="min-w-0">
                    <span className="block truncate text-xs font-semibold">
                        {log.branch?.name ?? 'Business-wide'}
                    </span>
                    <time className="block truncate text-[11px] text-neutral-500">
                        {dateTime.format(new Date(log.created_at))}
                    </time>
                </span>
                <span className="inline-flex min-h-9 items-center justify-center gap-1 rounded-lg border border-neutral-200 bg-white px-3 text-xs font-semibold transition group-hover:border-neutral-950">
                    Review <ChevronRight className="size-3.5" />
                </span>
            </span>
        </button>
    );
}

function EmptyState() {
    return (
        <div className="px-5 py-20 text-center">
            <span className="mx-auto flex size-14 items-center justify-center rounded-2xl bg-neutral-100">
                <History className="size-6 text-neutral-400" />
            </span>
            <p className="mt-4 text-sm font-bold">No activity matches</p>
            <p className="mt-1 text-xs text-neutral-500">
                Clear a filter or try another search term.
            </p>
        </div>
    );
}

function AuditDetail({ log, onClose }: { log: AuditLog; onClose: () => void }) {
    const style = actionStyle(log.action);
    const Icon = style.icon;
    const changes = auditChangeRows(log.before, log.after);
    const metadata = Object.entries(log.metadata ?? {});

    return (
        <Dialog open onOpenChange={(open) => !open && onClose()}>
            <DialogContent className="max-h-[82dvh] w-[calc(100vw-2rem)] gap-0 overflow-hidden border-0 p-0 shadow-2xl sm:max-w-5xl xl:max-w-6xl">
                <div className="border-b border-neutral-100 bg-gradient-to-br from-neutral-50 via-white to-white px-5 py-5 sm:px-6">
                    <div className="flex items-start gap-3">
                        <span
                            className={`flex size-11 shrink-0 items-center justify-center rounded-2xl ring-1 ${style.iconClass}`}
                        >
                            <Icon className="size-5" />
                        </span>
                        <div className="min-w-0 flex-1">
                            <div className="flex flex-wrap items-center gap-2">
                                <DialogTitle className="text-xl font-bold tracking-tight">
                                    {auditActionLabel(log.action)}
                                </DialogTitle>
                                <OwnerStatusBadge tone={style.badge}>
                                    {moduleLabel(log.module)}
                                </OwnerStatusBadge>
                            </div>
                            <DialogDescription className="mt-1 text-xs leading-5 text-neutral-600">
                                Immutable audit event recorded{' '}
                                {dateTime.format(new Date(log.created_at))}
                            </DialogDescription>
                        </div>
                    </div>
                </div>
                <div className="max-h-[calc(82dvh-108px)] space-y-4 overflow-y-auto bg-neutral-50/70 p-4 sm:p-6">
                    <section className="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                        <DetailStat
                            icon={CircleUserRound}
                            label="Actor"
                            value={auditActorName(log.actor)}
                        />
                        <DetailStat
                            icon={Store}
                            label="Branch"
                            value={
                                log.branch
                                    ? `${log.branch.name} · ${log.branch.code}`
                                    : 'Business-wide'
                            }
                        />
                        <DetailStat
                            icon={Clock3}
                            label="Date & time"
                            value={dateTime.format(new Date(log.created_at))}
                        />
                        <DetailStat
                            icon={Database}
                            label="Record"
                            value={`${log.auditable_type} · ${shortId(log.auditable_id)}`}
                        />
                    </section>

                    <section className={`${ownerPanelClass} overflow-hidden`}>
                        <div className="flex items-center justify-between border-b border-neutral-100 px-4 py-3">
                            <div>
                                <h3 className="text-sm font-bold">
                                    Recorded changes
                                </h3>
                                <p className="mt-0.5 text-xs text-neutral-500">
                                    Human-readable before and after values
                                </p>
                            </div>
                            <OwnerStatusBadge tone="outline">
                                {changes.length} changed
                            </OwnerStatusBadge>
                        </div>
                        {changes.length === 0 ? (
                            <div className="px-4 py-8 text-center text-xs text-neutral-500">
                                This event did not change tracked field values.
                            </div>
                        ) : (
                            <div className="divide-y divide-neutral-100">
                                {changes.map((change) => (
                                    <div
                                        key={change.field}
                                        className="grid gap-2 px-4 py-3 sm:grid-cols-[140px_1fr_24px_1fr] sm:items-center"
                                    >
                                        <span className="text-xs font-semibold text-neutral-600">
                                            {change.field}
                                        </span>
                                        <ValuePill
                                            value={change.before}
                                            muted
                                        />
                                        <ArrowRight className="hidden size-4 text-neutral-300 sm:block" />
                                        <ValuePill value={change.after} />
                                    </div>
                                ))}
                            </div>
                        )}
                    </section>

                    <div className="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
                        <section
                            className={`${ownerPanelClass} overflow-hidden`}
                        >
                            <div className="border-b border-neutral-100 px-4 py-3">
                                <h3 className="text-sm font-bold">
                                    Event identity
                                </h3>
                            </div>
                            <dl className="divide-y divide-neutral-100">
                                <InfoRow label="Event ID" value={log.id} />
                                <InfoRow
                                    label="Action key"
                                    value={log.action}
                                />
                                <InfoRow
                                    label="Record ID"
                                    value={log.auditable_id}
                                />
                                {log.actor?.email && (
                                    <InfoRow
                                        label="Actor email"
                                        value={log.actor.email}
                                    />
                                )}
                            </dl>
                        </section>
                        <section
                            className={`${ownerPanelClass} overflow-hidden`}
                        >
                            <div className="border-b border-neutral-100 px-4 py-3">
                                <h3 className="text-sm font-bold">
                                    Context metadata
                                </h3>
                            </div>
                            {metadata.length === 0 ? (
                                <p className="px-4 py-8 text-center text-xs text-neutral-500">
                                    No additional context was recorded.
                                </p>
                            ) : (
                                <dl className="divide-y divide-neutral-100">
                                    {metadata.map(([key, value]) => (
                                        <InfoRow
                                            key={key}
                                            label={titleCase(
                                                key.replaceAll('_', ' '),
                                            )}
                                            value={displayValue(value)}
                                        />
                                    ))}
                                </dl>
                            )}
                        </section>
                    </div>

                    <div className="flex gap-2 rounded-2xl border border-emerald-100 bg-emerald-50 px-4 py-3 text-[11px] leading-5 text-emerald-900">
                        <ShieldCheck className="mt-0.5 size-4 shrink-0 text-emerald-700" />
                        <span>
                            <strong className="block">
                                Protected audit record
                            </strong>
                            This entry is append-only and cannot be edited or
                            deleted from the Super Admin workspace.
                        </span>
                    </div>
                </div>
            </DialogContent>
        </Dialog>
    );
}

function DetailStat({
    icon: Icon,
    label,
    value,
}: {
    icon: LucideIcon;
    label: string;
    value: string;
}) {
    return (
        <div className="min-w-0 rounded-2xl border border-neutral-200 bg-white p-3 shadow-sm">
            <div className="mb-2 flex items-center gap-1.5 text-[10px] font-semibold tracking-wide text-neutral-500 uppercase">
                <Icon className="size-3.5" />
                {label}
            </div>
            <p className="truncate text-xs font-bold" title={value}>
                {value}
            </p>
        </div>
    );
}

function ValuePill({
    value,
    muted = false,
}: {
    value: string;
    muted?: boolean;
}) {
    return (
        <span
            className={`min-w-0 rounded-xl border px-3 py-2 text-xs break-words ${muted ? 'border-neutral-200 bg-neutral-50 text-neutral-500' : 'border-blue-100 bg-blue-50 font-semibold text-blue-900'}`}
        >
            {value}
        </span>
    );
}

function InfoRow({ label, value }: { label: string; value: string }) {
    return (
        <div className="grid gap-1 px-4 py-3 sm:grid-cols-[120px_1fr] sm:gap-3">
            <dt className="text-[11px] text-neutral-500">{label}</dt>
            <dd className="text-xs font-semibold break-all sm:text-right">
                {value}
            </dd>
        </div>
    );
}

function Pagination({ links }: { links: PageLink[] }) {
    const previousUrl = links[0]?.url;
    const nextUrl = links.at(-1)?.url;

    return (
        <div className="flex items-center justify-between border-t border-neutral-100 bg-neutral-50/60 p-3">
            <button
                type="button"
                disabled={!previousUrl}
                onClick={() =>
                    previousUrl &&
                    router.get(previousUrl, {}, { preserveScroll: true })
                }
                className={ownerSecondaryActionClass}
            >
                <ChevronLeft className="mr-1 inline size-4" /> Previous
            </button>
            <button
                type="button"
                disabled={!nextUrl}
                onClick={() =>
                    nextUrl && router.get(nextUrl, {}, { preserveScroll: true })
                }
                className={ownerSecondaryActionClass}
            >
                Next <ChevronRight className="ml-1 inline size-4" />
            </button>
        </div>
    );
}

function actionStyle(action: string) {
    if (actionStyles[action]) {
        return actionStyles[action];
    }
    if (action.includes('login') || action.includes('signed')) {
        return {
            icon: LogIn,
            iconClass: 'bg-blue-50 text-blue-700 ring-blue-100',
            badge: 'blue' as const,
        };
    }
    if (action.includes('created')) {
        return {
            icon: CirclePlus,
            iconClass: 'bg-emerald-50 text-emerald-700 ring-emerald-100',
            badge: 'green' as const,
        };
    }
    if (action.includes('updated') || action.includes('edited')) {
        return {
            icon: Pencil,
            iconClass: 'bg-amber-50 text-amber-700 ring-amber-100',
            badge: 'amber' as const,
        };
    }

    return {
        icon: History,
        iconClass: 'bg-neutral-100 text-neutral-700 ring-neutral-200',
        badge: 'neutral' as const,
    };
}

function moduleLabel(module: string): string {
    return titleCase(module.replaceAll('_', ' '));
}

function shortId(value: string): string {
    return value.length > 16
        ? `${value.slice(0, 8)}…${value.slice(-4)}`
        : value;
}

function displayValue(value: unknown): string {
    if (value === null || value === undefined || value === '') {
        return 'Not set';
    }
    if (typeof value === 'boolean') {
        return value ? 'Yes' : 'No';
    }
    if (typeof value === 'string' || typeof value === 'number') {
        return String(value);
    }
    if (Array.isArray(value)) {
        return value.length === 0
            ? 'None'
            : value.every((item) => ['string', 'number'].includes(typeof item))
              ? value.join(', ')
              : `${value.length} entries`;
    }
    if (typeof value === 'object') {
        const entries = Object.entries(value);

        return entries.length === 0
            ? 'None'
            : entries
                  .map(
                      ([key, nested]) =>
                          `${titleCase(key.replaceAll('_', ' '))}: ${displayValue(nested)}`,
                  )
                  .join(' · ');
    }

    return 'Unavailable';
}
