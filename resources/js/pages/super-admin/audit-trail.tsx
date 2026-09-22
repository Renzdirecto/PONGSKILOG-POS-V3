import { Head, router } from '@inertiajs/react';
import { ChevronLeft, ChevronRight, Filter, History } from 'lucide-react';
import { useState } from 'react';
import { OwnerPage, OwnerStatusBadge, ownerControlClass, ownerPanelClass } from '@/components/owner-ui';
import { Dialog, DialogContent, DialogDescription, DialogTitle } from '@/components/ui/dialog';
import { auditTrail } from '@/routes/workspaces';

type SelectOption = { id: string | number; name: string; code?: string; email?: string };
type PageLink = { url: string | null; label: string; active: boolean };
type AuditLog = {
    id: string;
    created_at: string;
    branch: { id: string; name: string; code: string } | null;
    actor: { id: number; name: string; email: string } | null;
    module: string;
    action: string;
    auditable_type: string;
    auditable_id: string;
    before: Record<string, unknown> | null;
    after: Record<string, unknown> | null;
    metadata: Record<string, unknown> | null;
};
type Props = {
    logs: { data: AuditLog[]; links: PageLink[]; from: number | null; to: number | null; total: number };
    filters: Record<string, string>;
    branches: SelectOption[];
    users: SelectOption[];
    modules: string[];
    actions: string[];
};

const dateTime = new Intl.DateTimeFormat('en-PH', { dateStyle: 'medium', timeStyle: 'short', timeZone: 'Asia/Manila' });

export default function AuditTrail({ logs, filters, branches, users, modules, actions }: Props) {
    const [selected, setSelected] = useState<AuditLog | null>(null);
    const apply = (next: Record<string, string>) => router.get(auditTrail(), { ...filters, ...next }, { preserveScroll: true, replace: true });
    const clear = () => router.get(auditTrail(), {}, { preserveScroll: true, replace: true });

    return (
        <>
            <Head title="Audit trail" />
            <OwnerPage title="Audit trail" description="Append-only operational history across every branch. Audit entries cannot be edited or deleted.">
                <section className={`${ownerPanelClass} grid gap-2 p-3 sm:grid-cols-2 lg:grid-cols-5`}>
                    <Select label="Branch" value={filters.branch_id ?? ''} options={branches.map((branch) => [String(branch.id), `${branch.name} · ${branch.code}`])} onChange={(branch_id) => apply({ branch_id })} />
                    <Select label="Actor" value={filters.user_id ?? ''} options={users.map((user) => [String(user.id), `${user.name} · ${user.email}`])} onChange={(user_id) => apply({ user_id })} />
                    <Select label="Module" value={filters.module ?? ''} options={modules.map((module) => [module, module])} onChange={(module) => apply({ module })} />
                    <Select label="Action" value={filters.action ?? ''} options={actions.map((action) => [action, action])} onChange={(action) => apply({ action })} />
                    <label className="grid gap-1 text-[11px] font-semibold text-neutral-600">
                        Date
                        <input type="date" value={filters.date ?? ''} onChange={(event) => apply({ date: event.target.value })} className={ownerControlClass} />
                    </label>
                    <button type="button" onClick={clear} className="inline-flex min-h-11 items-center justify-center gap-2 rounded-[11px] border border-neutral-200 px-3 text-[12px] font-semibold hover:border-neutral-950 sm:col-span-2 lg:col-span-5">
                        <Filter className="size-4" /> Clear filters
                    </button>
                </section>
                <section className={`${ownerPanelClass} overflow-hidden`}>
                    <div className="border-b border-neutral-100 px-4 py-3 text-sm font-semibold">{logs.total} audit entries</div>
                    {logs.data.length === 0 ? (
                        <div className="px-5 py-16 text-center text-sm text-neutral-500">No audit entries match these filters.</div>
                    ) : (
                        <div className="divide-y divide-neutral-100">
                            {logs.data.map((log) => (
                                <button key={log.id} type="button" onClick={() => setSelected(log)} className="grid w-full gap-2 px-4 py-3 text-left hover:bg-neutral-50 md:grid-cols-[180px_minmax(160px,1fr)_minmax(160px,1fr)_auto] md:items-center">
                                    <time className="text-xs tabular-nums text-neutral-500">{dateTime.format(new Date(log.created_at))}</time>
                                    <span className="min-w-0"><strong className="block truncate text-sm">{log.action}</strong><span className="block truncate text-xs text-neutral-500">{log.module} · {log.auditable_type}</span></span>
                                    <span className="min-w-0 text-xs"><strong className="block truncate">{log.actor?.name ?? 'System'}</strong><span className="block truncate text-neutral-500">{log.branch ? `${log.branch.name} · ${log.branch.code}` : 'No branch'}</span></span>
                                    <OwnerStatusBadge tone="outline">View</OwnerStatusBadge>
                                </button>
                            ))}
                        </div>
                    )}
                    <Pagination links={logs.links} />
                </section>
            </OwnerPage>
            {selected && <AuditDetail log={selected} onClose={() => setSelected(null)} />}
        </>
    );
}

function Select({ label, value, options, onChange }: { label: string; value: string; options: [string, string][]; onChange: (value: string) => void }) {
    return <label className="grid gap-1 text-[11px] font-semibold text-neutral-600">{label}<select value={value} onChange={(event) => onChange(event.target.value)} className={ownerControlClass}><option value="">All {label.toLowerCase()}s</option>{options.map(([value, label]) => <option key={value} value={value}>{label}</option>)}</select></label>;
}

function AuditDetail({ log, onClose }: { log: AuditLog; onClose: () => void }) {
    return <Dialog open onOpenChange={(open) => !open && onClose()}><DialogContent className="max-h-[90dvh] max-w-2xl overflow-y-auto p-5"><History className="size-5 text-neutral-500" /><DialogTitle>{log.action}</DialogTitle><DialogDescription>{dateTime.format(new Date(log.created_at))} · {log.auditable_type} {log.auditable_id}</DialogDescription><AuditBlock label="Before" value={log.before} /><AuditBlock label="After" value={log.after} /><AuditBlock label="Metadata" value={log.metadata} /></DialogContent></Dialog>;
}

function AuditBlock({ label, value }: { label: string; value: Record<string, unknown> | null }) {
    return <section><p className="mb-1 text-[10px] font-semibold tracking-[.08em] text-neutral-500 uppercase">{label}</p><pre className="overflow-x-auto rounded-xl bg-neutral-950 p-3 text-xs leading-5 text-neutral-100">{JSON.stringify(value ?? {}, null, 2)}</pre></section>;
}

function Pagination({ links }: { links: PageLink[] }) {
    const previous = links[0]; const next = links.at(-1);
    return <div className="flex items-center justify-between border-t border-neutral-100 p-3"><button type="button" disabled={!previous?.url} onClick={() => previous.url && router.get(previous.url)} className="inline-flex h-10 items-center gap-1 rounded-lg border border-neutral-200 px-3 text-xs font-semibold disabled:opacity-40"><ChevronLeft className="size-4" /> Previous</button><button type="button" disabled={!next?.url} onClick={() => next.url && router.get(next.url)} className="inline-flex h-10 items-center gap-1 rounded-lg border border-neutral-200 px-3 text-xs font-semibold disabled:opacity-40">Next <ChevronRight className="size-4" /></button></div>;
}
