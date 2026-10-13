<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The landing page's tap-to-love (v1.3.0). Taps are kept as one count per Asia/Manila clock hour, never an IP address,
 * session or account. The number every visitor shares is the sum of all hours and the Owner's Heart Taps is the sum
 * of the hours in a period, so the two always agree. The page reads the total once per visit instead of polling.
 */
class LandingLove
{
    /** The most taps one request may add; the page sends quick taps as one batch (`LOVE_MAX_TAPS`). */
    public const MAX_TAPS = 20;

    /** The all-time total shown on the landing page. */
    public function total(): int
    {
        return (int) DB::table('landing_loves')->sum('taps');
    }

    /**
     * Adds a batch of taps to the current hour in one atomic statement, so simultaneous visitors never overwrite each
     * other. A batch key already seen in the last ten minutes (a retry or a double send) adds nothing.
     */
    public function add(string $key, int $taps): int
    {
        $seen = 'landing-love:'.$key;
        if (Cache::add($seen, true, now()->addMinutes(10))) {
            $now = CarbonImmutable::now(ReportPeriod::TIMEZONE);
            try {
                DB::table('landing_loves')->upsert(
                    [['occurred_on' => $now->toDateString(), 'hour' => $now->hour, 'taps' => $taps]],
                    ['occurred_on', 'hour'],
                    ['taps' => DB::raw('landing_loves.taps + excluded.taps')],
                );
            } catch (Throwable $exception) {
                /** Nothing was added, so the page's retry of this batch must still count. */
                Cache::forget($seen);

                throw $exception;
            }
        }

        return $this->total();
    }

    /**
     * Taps of one reporting period in a single aggregate query, keyed like the Website Analytics series: by Manila
     * clock hour for one day, by Manila date for a longer period.
     *
     * @return array<string, int>
     */
    public function during(ReportPeriod $period): array
    {
        $bucket = $period->granularity === 'hour' ? 'hour' : 'occurred_on';

        return DB::table('landing_loves')
            ->whereBetween('occurred_on', [$period->from->toDateString(), $period->through->toDateString()])
            ->selectRaw("{$bucket} AS bucket, SUM(taps) AS taps")
            ->groupBy($bucket)->get()
            ->mapWithKeys(fn (object $row): array => [substr((string) $row->bucket, 0, 10) => (int) $row->taps])
            ->all();
    }
}
