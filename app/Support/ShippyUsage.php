<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Shippy's usage, kept as one row of counters per Asia/Manila date (v1.3.0): questions answered, Gemini requests made,
 * the tokens Gemini reported and requests that failed. The same row is the daily quota every Gemini call is counted
 * against before it is made, and what Website → Shippy shows. No message text and nothing about a guest is stored.
 */
class ShippyUsage
{
    /** The days of usage Website → Shippy reads. */
    public const DAYS = 30;

    /** Counts one question a guest asked, however it ends up being answered. */
    public function message(): void
    {
        $this->add(['messages' => 1]);
    }

    /**
     * Counts one Gemini request against today's quota, and refuses once the day's limit is reached. The check and
     * the count are one statement, so simultaneous guests cannot overshoot the limit together.
     */
    public function reserve(int $limit): bool
    {
        DB::table('shippy_usage')->insertOrIgnore(['used_on' => $this->today()]);

        return DB::table('shippy_usage')->where('used_on', $this->today())->where('requests', '<', $limit)->increment('requests') === 1;
    }

    /** Adds the tokens Gemini reported for one request. */
    public function tokens(int $input, int $output): void
    {
        $this->add(['input_tokens' => max(0, $input), 'output_tokens' => max(0, $output)]);
    }

    /** Counts one Gemini request that failed or whose answer could not be used. */
    public function error(): void
    {
        $this->add(['errors' => 1]);
    }

    /**
     * Today's and the last 30 days' usage from one bounded query, with the cost those tokens would have at the
     * configured list price. The cost is an estimate: it is never read from a bill.
     *
     * @return array{today: array<string, int|float>, month: array<string, int|float>, days: int, daily_limit: int}
     */
    public function summary(): array
    {
        $today = $this->today();
        $rows = DB::table('shippy_usage')
            ->where('used_on', '>=', CarbonImmutable::parse($today)->subDays(self::DAYS - 1)->toDateString())
            ->get();

        return [
            'today' => $this->totals($rows->filter(fn (object $row): bool => substr((string) $row->used_on, 0, 10) === $today)->all()),
            'month' => $this->totals($rows->all()),
            'days' => self::DAYS,
            'daily_limit' => (int) config('services.gemini.requests_per_day'),
        ];
    }

    /**
     * @param  array<int, object>  $rows
     * @return array{messages: int, requests: int, errors: int, input_tokens: int, output_tokens: int, estimated_usd: float}
     */
    private function totals(array $rows): array
    {
        $sum = fn (string $column): int => (int) array_sum(array_map(fn (object $row): int => (int) $row->{$column}, $rows));
        $input = $sum('input_tokens');
        $output = $sum('output_tokens');

        return [
            'messages' => $sum('messages'), 'requests' => $sum('requests'), 'errors' => $sum('errors'),
            'input_tokens' => $input, 'output_tokens' => $output,
            'estimated_usd' => round(
                ($input * (float) config('services.gemini.input_usd_per_million') + $output * (float) config('services.gemini.output_usd_per_million')) / 1_000_000,
                4,
            ),
        ];
    }

    /**
     * Adds to today's counters in one atomic statement.
     *
     * @param  array<string, int>  $counts
     */
    private function add(array $counts): void
    {
        $sums = [
            'messages' => DB::raw('shippy_usage.messages + excluded.messages'),
            'errors' => DB::raw('shippy_usage.errors + excluded.errors'),
            'input_tokens' => DB::raw('shippy_usage.input_tokens + excluded.input_tokens'),
            'output_tokens' => DB::raw('shippy_usage.output_tokens + excluded.output_tokens'),
        ];
        DB::table('shippy_usage')->upsert([['used_on' => $this->today(), ...$counts]], ['used_on'], array_intersect_key($sums, $counts));
    }

    private function today(): string
    {
        return CarbonImmutable::now(ReportPeriod::TIMEZONE)->toDateString();
    }
}
