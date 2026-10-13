import { Head, router, usePage } from '@inertiajs/react';
import {
    CalendarDays,
    Eye,
    MapPin,
    MessageCircle,
    Star,
    Users,
    UtensilsCrossed,
} from 'lucide-react';
import { useState } from 'react';
import {
    AnalyticsCard,
    KpiCard,
    SegmentedTabs,
    TrendChart,
} from '@/components/owner-analytics';
import {
    OwnerPage,
    ownerPanelClass,
    ownerPrimaryActionClass,
} from '@/components/owner-ui';
import { countLabel } from '@/lib/owner-analytics';
import type { TrendBucket } from '@/lib/owner-analytics';
import { customRangeError } from '@/lib/reports';
import {
    WEBSITE_PERIODS,
    websiteAnalyticsQuery,
} from '@/lib/website-analytics';
import type { WebsitePeriod, WebsiteReport } from '@/lib/website-analytics';
import { websiteAnalytics } from '@/routes/workspaces';

const labelClass =
    'text-[10.5px] font-semibold tracking-[0.07em] text-[#767676] uppercase';

const count = (value: number): string => value.toLocaleString('en-PH');

export default function WebsiteAnalytics({
    report,
}: {
    report: WebsiteReport;
}) {
    const { errors } = usePage<{ errors: Record<string, string> }>().props;
    const { period, totals, series } = report;
    const [customOpen, setCustomOpen] = useState(period.preset === 'custom');
    const [from, setFrom] = useState(period.from);
    const [to, setTo] = useState(period.to);
    const [customError, setCustomError] = useState<string | null>(null);
    const [loading, setLoading] = useState(false);
    const active: WebsitePeriod = customOpen ? 'custom' : period.preset;
    const visitors = new Map(
        series.map((bucket) => [bucket.key, bucket.visitors]),
    );
    /** The shared Owner trend chart plots page views as its count series; the wording comes from `text` below. */
    const buckets: TrendBucket[] = series.map((bucket) => ({
        key: bucket.key,
        label: bucket.label,
        full: bucket.full,
        sales: '0.00',
        sales_cents: 0,
        transactions: bucket.page_views,
        previous: null,
    }));

    function visit(next: WebsitePeriod, range?: { from: string; to: string }) {
        router.get(
            websiteAnalytics(),
            websiteAnalyticsQuery(next, range?.from, range?.to),
            {
                preserveState: true,
                preserveScroll: true,
                replace: true,
                only: ['report', 'errors'],
                onStart: () => setLoading(true),
                onFinish: () => setLoading(false),
            },
        );
    }

    function choose(next: WebsitePeriod) {
        setCustomError(null);
        setCustomOpen(next === 'custom');
        if (next === 'custom') {
            /** Custom starts from the range on screen, so narrowing it is one edit. */
            setFrom(period.from);
            setTo(period.to);
        } else {
            visit(next);
        }
    }

    function applyCustom() {
        const error = customRangeError(from, to);
        setCustomError(error);
        if (error === null) {
            visit('custom', { from, to });
        }
    }

    /** [label, phone label, value, note, icon]: the phone label fits a two-column card without being cut. */
    const cards = [
        [
            'Page views',
            'Page views',
            count(totals.page_views),
            'Landing page loads',
            Eye,
        ],
        [
            'Unique visitors (estimate)',
            'Est. visitors',
            `≈ ${count(totals.visitors)}`,
            'Estimate',
            Users,
        ],
        [
            'Facebook / contact clicks',
            'FB / contact',
            count(totals.facebook_clicks),
            'Facebook links',
            MessageCircle,
        ],
        [
            'Directions clicks',
            'Directions',
            count(totals.directions_clicks),
            'Map links',
            MapPin,
        ],
        [
            'Menu clicks',
            'Menu clicks',
            count(totals.menu_clicks),
            'Menu and filter taps',
            UtensilsCrossed,
        ],
        [
            'Review submissions',
            'Reviews',
            count(totals.review_submissions),
            'Saved reviews',
            Star,
        ],
    ] as const;

    return (
        <>
            <Head title="Website Analytics" />
            <OwnerPage
                title="Website Analytics"
                description="Real visits and taps on the public landing page, counted by PONGSKILOG itself. Business-wide: the website is not tied to one Branch."
            >
                <section
                    aria-label="Reporting period"
                    className={`${ownerPanelClass} flex flex-col gap-3 px-3.5 py-3`}
                >
                    <div className="flex flex-wrap items-center gap-2.5">
                        <div className="flex min-w-0 flex-[1_1_200px] flex-col gap-0.5">
                            <span className={labelClass}>Reporting period</span>
                            <span className="truncate text-sm font-bold tracking-[-0.01em]">
                                {period.label}
                            </span>
                            <span className="text-[11px] text-[#8a8a8a]">
                                Public landing page · Philippine time
                            </span>
                        </div>
                        <SegmentedTabs
                            label="Reporting period"
                            value={active}
                            options={WEBSITE_PERIODS}
                            onChange={choose}
                        />
                    </div>
                    {customOpen && (
                        <div className="flex flex-col gap-1.5">
                            <span className={labelClass}>Custom range</span>
                            <div className="flex flex-wrap items-center gap-2">
                                <div className="flex min-h-11 max-w-full min-w-0 items-center gap-[7px] rounded-[11px] border border-[#e5e5e5] bg-white px-[11px]">
                                    <CalendarDays
                                        className="size-4 shrink-0 text-[#767676] max-[389px]:hidden"
                                        aria-hidden="true"
                                    />
                                    <input
                                        type="date"
                                        value={from}
                                        max={to || undefined}
                                        aria-label="From date"
                                        onChange={(event) =>
                                            setFrom(event.target.value)
                                        }
                                        className="w-[128px] min-w-0 border-0 bg-transparent text-base outline-none sm:text-[12.5px]"
                                    />
                                    <span className="text-[#c9c9c9]">–</span>
                                    <input
                                        type="date"
                                        value={to}
                                        min={from || undefined}
                                        aria-label="To date"
                                        onChange={(event) =>
                                            setTo(event.target.value)
                                        }
                                        className="w-[128px] min-w-0 border-0 bg-transparent text-base outline-none sm:text-[12.5px]"
                                    />
                                </div>
                                <button
                                    type="button"
                                    onClick={applyCustom}
                                    disabled={loading}
                                    className={`${ownerPrimaryActionClass} inline-flex items-center justify-center gap-2 disabled:opacity-60`}
                                >
                                    Apply range
                                </button>
                            </div>
                            <span className="text-[11px] text-[#767676]">
                                Up to 31 days, in Philippine time.
                            </span>
                            {(customError ?? errors.from ?? errors.to) && (
                                <p
                                    role="alert"
                                    className="text-[12.5px] font-semibold text-red-700"
                                >
                                    {customError ?? errors.from ?? errors.to}
                                </p>
                            )}
                        </div>
                    )}
                </section>

                <div
                    aria-busy={loading}
                    className={`flex flex-col gap-3 transition-opacity ${loading ? 'opacity-60' : ''}`}
                >
                    <section
                        aria-label="Key figures"
                        className="grid grid-cols-2 gap-3 min-[900px]:grid-cols-3"
                    >
                        {cards.map(([label, short, value, note, Icon]) => (
                            <KpiCard
                                key={label}
                                label={
                                    <>
                                        <span className="sm:hidden">
                                            {short}
                                        </span>
                                        <span className="hidden sm:inline">
                                            {label}
                                        </span>
                                    </>
                                }
                                value={value}
                                delta={null}
                                comparison={note}
                                icon={<Icon className="size-4" />}
                            />
                        ))}
                    </section>

                    <AnalyticsCard
                        title="Visits over time"
                        hint={`${countLabel(totals.page_views, 'page view')} · about ${countLabel(totals.visitors, 'visitor')} · ${period.granularity === 'hour' ? 'by hour' : 'by day'}`}
                    >
                        <TrendChart
                            buckets={buckets}
                            metric="transactions"
                            compare={false}
                            height={182}
                            text={{
                                primary: (bucket) =>
                                    countLabel(
                                        bucket.transactions,
                                        'page view',
                                    ),
                                secondary: (bucket) =>
                                    `About ${countLabel(visitors.get(bucket.key) ?? 0, 'visitor')}`,
                                empty: 'No visits recorded in this period',
                            }}
                        />
                    </AnalyticsCard>

                    <AnalyticsCard
                        title="How this is counted"
                        hint={
                            report.tracking_since
                                ? `Counting started ${report.tracking_since}. Earlier dates show zero because nothing was recorded, not because nobody visited.`
                                : 'Nothing has been recorded yet. Figures appear after the first visit to the landing page.'
                        }
                    >
                        <ul className="flex list-disc flex-col gap-1.5 pl-4 text-[12.5px] leading-5 text-[#555]">
                            <li>
                                <strong>
                                    Unique visitors are an estimate.
                                </strong>{' '}
                                One browser counts once per day. The same person
                                on another day, device or browser counts again,
                                and a period total adds each day&apos;s
                                visitors.
                            </li>
                            <li>
                                Only the public landing page is counted.
                                Signed-in staff, workspace pages and
                                recognizable bots are left out.
                            </li>
                            <li>
                                No IP address, name, device details or
                                third-party tracker is stored or used. Counts
                                are kept for 12 months.
                            </li>
                        </ul>
                    </AnalyticsCard>
                </div>
            </OwnerPage>
        </>
    );
}
