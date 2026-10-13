<?php

namespace App\Support;

use App\Models\WebsiteEvent;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * First-party, anonymous counters for the public landing page (v1.2.1). The landing page reports its own page views
 * and Facebook / directions / menu taps; a saved review is counted by the server. Nothing identifying is kept: no IP
 * address, user agent, URL or account, only a keyed hash of the visitor's session that changes every day, so unique
 * visitors are an estimate and nobody can be followed from one day to the next. Workspace pages are never tracked.
 */
class WebsiteAnalytics
{
    /** Events the landing page itself may report. */
    public const CLIENT_EVENTS = ['page_view', 'facebook_click', 'directions_click', 'menu_click'];

    /** Recorded only by the server, when a review is actually saved. */
    public const REVIEW_SUBMISSION = 'review_submission';

    /** The Owner page periods; Custom is a range of at most `ReportPeriod::MAX_CUSTOM_DAYS` days. */
    public const PERIODS = ['today', 'last_7_days', 'last_30_days', 'custom'];

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|headless|lighthouse|pagespeed|preview|monitor|pingdom|uptime|scrape|curl|wget|python|httpclient|facebookexternalhit|whatsapp|telegram/i';

    /** Signed-in accounts (staff looking at the landing page) and recognizable bots are never counted. */
    public function counts(Request $request): bool
    {
        $agent = (string) $request->userAgent();

        return $request->user() === null && $agent !== '' && preg_match(self::BOT_PATTERN, $agent) !== 1;
    }

    public function record(Request $request, string $event): void
    {
        $now = CarbonImmutable::now(ReportPeriod::TIMEZONE);
        $date = $now->toDateString();

        WebsiteEvent::query()->create([
            'event' => $event,
            'visitor' => hash_hmac('sha256', $request->session()->getId().'|'.$date, (string) config('app.key')),
            'occurred_on' => $date,
            'created_at' => $now->utc(),
        ]);
    }

    /**
     * Totals and the page-view trend of one period, in three bounded aggregate queries. A single day is bucketed by
     * Manila clock hour, a longer period by Manila date. Visitors are distinct daily session hashes, so a multi-day
     * total is the sum of each day's visitors.
     *
     * @return array{refreshed_at: string, period: array{preset: string, from: string, to: string, label: string, days: int, granularity: string}, totals: array{page_views: int, visitors: int, facebook_clicks: int, directions_clicks: int, menu_clicks: int, review_submissions: int}, series: list<array{key: string, label: string, full: string, page_views: int, visitors: int}>, tracking_since: string|null}
     */
    public function report(ReportPeriod $period): array
    {
        $range = [$period->from->toDateString(), $period->through->toDateString()];
        $events = WebsiteEvent::query()->toBase()->whereBetween('occurred_on', $range)
            ->selectRaw('event, COUNT(*) AS total, COUNT(DISTINCT visitor) AS visitors')
            ->groupBy('event')->get()->keyBy('event');
        $total = fn (string $event): int => (int) ($events->get($event)->total ?? 0);

        $hourly = $period->granularity === 'hour';
        $bucket = $hourly ? ManilaSql::hour('created_at') : 'occurred_on';
        $views = WebsiteEvent::query()->toBase()->whereBetween('occurred_on', $range)->where('event', 'page_view')
            ->selectRaw("{$bucket} AS bucket, COUNT(*) AS total, COUNT(DISTINCT visitor) AS visitors")
            ->groupByRaw($bucket)->get()->keyBy(fn (object $row): string => (string) $row->bucket);
        $series = [];
        foreach ($hourly ? array_map(strval(...), range(0, 23)) : $period->bucketKeys() as $key) {
            $series[] = [
                'key' => $key,
                ...($hourly ? $this->hourLabels((int) $key) : $period->bucketLabels($key)),
                'page_views' => (int) ($views->get($key)->total ?? 0),
                'visitors' => (int) ($views->get($key)->visitors ?? 0),
            ];
        }
        $first = WebsiteEvent::query()->min('occurred_on');
        $present = $period->present();

        return [
            'period' => [
                'preset' => $present['preset'], 'from' => $present['from'], 'to' => $present['to'],
                'label' => $present['label'], 'days' => $present['days'], 'granularity' => $present['granularity'],
            ],
            'totals' => [
                'page_views' => $total('page_view'),
                'visitors' => (int) ($events->get('page_view')->visitors ?? 0),
                'facebook_clicks' => $total('facebook_click'),
                'directions_clicks' => $total('directions_click'),
                'menu_clicks' => $total('menu_click'),
                'review_submissions' => $total(self::REVIEW_SUBMISSION),
            ],
            'series' => $series,
            'refreshed_at' => CarbonImmutable::now(ReportPeriod::TIMEZONE)->toIso8601String(),
            'tracking_since' => is_string($first) ? ReportPeriod::day(substr($first, 0, 10))?->format('M j, Y') : null,
        ];
    }

    /** @return array{label: string, full: string} */
    private function hourLabels(int $hour): array
    {
        $long = fn (int $value): string => ($value % 12 === 0 ? 12 : $value % 12).($value < 12 ? ' AM' : ' PM');

        return ['label' => $long($hour), 'full' => $long($hour).' – '.$long(($hour + 1) % 24)];
    }
}
