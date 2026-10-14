import { router } from '@inertiajs/react';
import { RefreshCw } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { actionClass } from '@/components/catalog-ui';
import { OwnerStatusBadge, ownerPanelClass } from '@/components/owner-ui';
import { SettingSwitch } from '@/components/receipt-settings';
import { Button } from '@/components/ui/button';
import { WebsiteShippyKnowledge } from '@/components/website-shippy-knowledge';
import { qrError, qrRequest } from '@/lib/qr-http';
import { shippyCostLabel } from '@/lib/website-shippy';
import type { ShippyUsageTotals, WebsiteShippy } from '@/lib/website-shippy';
import { update } from '@/routes/workspaces/website/shippy';

const count = (value: number): string => value.toLocaleString('en-PH');

/**
 * Website → Shippy: the switch of the landing page chat, what the Owner taught it (Test Shippy, FAQs and Documents,
 * see `website-shippy-knowledge.tsx`) and what it has used. The figures are read once with the page (Refresh reads
 * them again; nothing polls) and the cost is an estimate from the tokens Gemini reported. The API key is environment
 * configuration: this page only says whether one is set.
 */
export function WebsiteShippySettings({ shippy }: { shippy: WebsiteShippy }) {
    const [busy, setBusy] = useState(false);
    const [error, setError] = useState('');
    const reload = () =>
        router.reload({ only: ['shippy'], onFinish: () => setBusy(false) });
    const turn = async (is_enabled: boolean) => {
        if (busy) return;
        setBusy(true);
        setError('');
        try {
            await qrRequest(update(), { is_enabled });
            toast.success(
                is_enabled ? 'Shippy is on' : 'Shippy is switched off',
            );
            reload();
        } catch (reason) {
            setError(qrError(reason).message);
            setBusy(false);
        }
    };
    const periods: [string, ShippyUsageTotals][] = [
        ['Today', shippy.usage.today],
        [`Last ${shippy.usage.days} days`, shippy.usage.month],
    ];

    return (
        <section aria-label="Shippy" className="flex flex-col gap-3">
            <div
                className={`${ownerPanelClass} flex flex-col gap-4 p-4 sm:p-5`}
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="text-lg font-bold">Shippy chat</h2>
                    <OwnerStatusBadge
                        tone={shippy.enabled ? 'green' : 'neutral'}
                    >
                        {shippy.enabled ? 'On landing page' : 'Switched off'}
                    </OwnerStatusBadge>
                </div>
                <p className="text-muted-foreground text-sm">
                    Shippy answers visitors&apos; questions about the menu,
                    prices, store hours and location from the public landing
                    page. Conversations are not saved.
                </p>
                <SettingSwitch
                    checked={shippy.enabled}
                    disabled={busy}
                    onChange={(enabled) => void turn(enabled)}
                    label="Show Shippy on the landing page"
                    description="Off removes the chat for every visitor at their next page load. Saved right away."
                />
                {error && (
                    <p
                        role="alert"
                        className="text-xs font-semibold text-red-700"
                    >
                        {error}
                    </p>
                )}
                <dl className="grid gap-x-6 gap-y-2 text-[12.5px] leading-5 sm:grid-cols-3">
                    <div>
                        <dt className="text-neutral-500">Gemini API key</dt>
                        <dd className="font-semibold">
                            {shippy.configured
                                ? 'Configured on the server'
                                : 'Not configured'}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-neutral-500">Model</dt>
                        <dd className="font-semibold break-all">
                            {shippy.model}
                        </dd>
                    </div>
                    <div>
                        <dt className="text-neutral-500">Daily Gemini limit</dt>
                        <dd className="font-semibold">
                            {count(shippy.usage.daily_limit)} requests
                        </dd>
                    </div>
                </dl>
                {!shippy.configured && (
                    <p className="text-xs text-neutral-600">
                        Without a key Shippy still answers, from the published
                        menu, your FAQs and your documents. The key is set in
                        the server environment and is never shown here.
                    </p>
                )}
            </div>

            <WebsiteShippyKnowledge shippy={shippy} />

            <div
                className={`${ownerPanelClass} flex flex-col gap-3 p-4 sm:p-5`}
            >
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h3 className="text-sm font-bold tracking-[0.08em] uppercase">
                        Usage
                    </h3>
                    <Button
                        type="button"
                        variant="outline"
                        className={`${actionClass} min-h-11`}
                        disabled={busy}
                        onClick={() => {
                            setBusy(true);
                            reload();
                        }}
                    >
                        <RefreshCw className="size-3.5" aria-hidden="true" />{' '}
                        Refresh
                    </Button>
                </div>
                <div className="overflow-x-auto">
                    <table className="w-full min-w-[520px] border-collapse text-left text-[12.5px]">
                        <thead>
                            <tr className="border-b border-neutral-200 text-[10.5px] tracking-[0.07em] text-neutral-500 uppercase">
                                <th scope="col" className="py-2 pr-3">
                                    Period
                                </th>
                                <th scope="col" className="py-2 pr-3">
                                    Questions
                                </th>
                                <th scope="col" className="py-2 pr-3">
                                    Gemini requests
                                </th>
                                <th scope="col" className="py-2 pr-3">
                                    Errors
                                </th>
                                <th scope="col" className="py-2 pr-3">
                                    Tokens in / out
                                </th>
                                <th scope="col" className="py-2">
                                    Estimated cost
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            {periods.map(([label, totals]) => (
                                <tr
                                    key={label}
                                    className="border-b border-neutral-100 tabular-nums last:border-0"
                                >
                                    <th
                                        scope="row"
                                        className="py-2.5 pr-3 font-semibold"
                                    >
                                        {label}
                                    </th>
                                    <td className="py-2.5 pr-3">
                                        {count(totals.messages)}
                                    </td>
                                    <td className="py-2.5 pr-3">
                                        {count(totals.requests)}
                                    </td>
                                    <td className="py-2.5 pr-3">
                                        {count(totals.errors)}
                                    </td>
                                    <td className="py-2.5 pr-3">
                                        {count(totals.input_tokens)} /{' '}
                                        {count(totals.output_tokens)}
                                    </td>
                                    <td className="py-2.5 font-semibold">
                                        ≈{' '}
                                        {shippyCostLabel(totals.estimated_usd)}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
                <ul className="flex list-disc flex-col gap-1.5 pl-4 text-[12.5px] leading-5 text-[#555]">
                    <li>
                        <strong>
                            Estimated cost is an estimate, not a bill.
                        </strong>{' '}
                        It multiplies the tokens Gemini reported by the
                        model&apos;s list price. Free-tier usage is not charged;
                        check Google AI Studio for what was really billed.
                    </li>
                    <li>
                        Questions counts every question asked. Gemini requests
                        counts only the ones sent to Gemini: a question the
                        menu, an FAQ or a document answers, a question past the
                        daily limit and any question while Gemini is unavailable
                        are answered without one.
                    </li>
                    <li>
                        Only these daily totals are kept. No message text and
                        nothing about a visitor is stored.
                    </li>
                </ul>
            </div>
        </section>
    );
}
