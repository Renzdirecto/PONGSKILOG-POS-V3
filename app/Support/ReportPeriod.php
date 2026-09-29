<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * A reporting period of whole Asia/Manila business dates plus the immediately comparable previous period.
 *
 * A business date is the Manila calendar date on which a Store Session opened, so a period selects Store Sessions by
 * their opening date and a session that crosses midnight stays under its opening date. `through` is the start of the
 * last included day. Granularity decides the trend buckets: one day by clock hour, up to 31 days by business date and
 * the twelve-month period by calendar month. The previous period always has the same number of buckets.
 */
final class ReportPeriod
{
    public const TIMEZONE = 'Asia/Manila';

    /** Every accepted preset; the Owner tabs are today, last_7_days, last_30_days, last_12_months and custom. */
    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'last_30_days', 'month', 'last_12_months', 'custom'];

    public const MAX_CUSTOM_DAYS = 31;

    /** Store Session drill-down is offered only for periods short enough to list their sessions. */
    public const MAX_SESSION_FILTER_DAYS = 31;

    /**
     * @param  'hour'|'day'|'month'  $granularity
     */
    private function __construct(
        public readonly string $preset,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $through,
        public readonly CarbonImmutable $previousFrom,
        public readonly CarbonImmutable $previousThrough,
        public readonly string $granularity,
        public readonly string $comparisonLabel,
        public readonly string $kicker,
    ) {}

    /**
     * @param  array{date?: string|null, from?: string|null, to?: string|null}  $filters
     */
    public static function fromFilters(array $filters): self
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $preset = in_array($filters['date'] ?? null, self::PRESETS, true) ? (string) $filters['date'] : 'today';

        return match ($preset) {
            'yesterday' => self::span($preset, $today->subDay(), $today->subDay(), 'previous day', 'Selected day'),
            'last_7_days' => self::span($preset, $today->subDays(6), $today, 'previous 7 days', 'Selected week'),
            'last_30_days' => self::span($preset, $today->subDays(29), $today, 'previous 30 days', 'Selected month'),
            'month' => new self(
                $preset, $today->startOfMonth(), $today,
                $today->startOfMonth()->subMonthNoOverflow(), $today->subMonthNoOverflow(),
                'day', 'same days last month', 'This month',
            ),
            'last_12_months' => new self(
                $preset, $today->subMonthsNoOverflow(11)->startOfMonth(), $today,
                $today->subMonthsNoOverflow(11)->startOfMonth()->subYear(), $today->subYear(),
                'month', 'previous year', 'Selected year',
            ),
            'custom' => self::custom($filters, $today),
            default => self::span($preset, $today, $today, 'yesterday', 'Selected day'),
        };
    }

    public static function day(string $date): ?CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);

        return $day instanceof CarbonImmutable && $day->toDateString() === $date ? $day : null;
    }

    public function days(): int
    {
        return (int) $this->from->diffInDays($this->through) + 1;
    }

    public function allowsSessionFilter(): bool
    {
        return $this->days() <= self::MAX_SESSION_FILTER_DAYS;
    }

    /**
     * UTC instant bounds of the current or previous period's business dates: [start, end).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bounds(bool $previous = false): array
    {
        $from = $previous ? $this->previousFrom : $this->from;
        $through = $previous ? $this->previousThrough : $this->through;

        return [$from->utc(), $through->addDay()->utc()];
    }

    /**
     * The ordered bucket keys of the current or previous period. Hour buckets are clock hours (0–23) and are shaped
     * by the caller, day buckets are Y-m-d business dates and month buckets are Y-m.
     *
     * @return list<string>
     */
    public function bucketKeys(bool $previous = false): array
    {
        $from = $previous ? $this->previousFrom : $this->from;
        $through = $previous ? $this->previousThrough : $this->through;
        $keys = [];
        if ($this->granularity === 'month') {
            for ($month = $from->startOfMonth(); $month->lessThanOrEqualTo($through); $month = $month->addMonthNoOverflow()) {
                $keys[] = $month->format('Y-m');
            }

            return $keys;
        }
        for ($day = $from; $day->lessThanOrEqualTo($through); $day = $day->addDay()) {
            $keys[] = $day->toDateString();
        }

        return $keys;
    }

    public function bucketKeyOf(string $businessDate): string
    {
        return $this->granularity === 'month' ? substr($businessDate, 0, 7) : $businessDate;
    }

    /** @return array{label: string, full: string} */
    public function bucketLabels(string $key): array
    {
        if ($this->granularity === 'month') {
            $month = CarbonImmutable::createFromFormat('!Y-m', $key, self::TIMEZONE) ?: $this->from;

            return ['label' => $month->format('M'), 'full' => $month->format('M Y')];
        }
        $day = self::day($key) ?? $this->from;

        return ['label' => $day->format('M j'), 'full' => $day->format('M j, Y')];
    }

    /**
     * @return array{preset: string, from: string, to: string, label: string, days: int, granularity: string, kicker: string, comparison: array{from: string, to: string, label: string, description: string}, session_filter_available: bool}
     */
    public function present(): array
    {
        return [
            'preset' => $this->preset,
            'from' => $this->from->toDateString(),
            'to' => $this->through->toDateString(),
            'label' => $this->label($this->from, $this->through),
            'days' => $this->days(),
            'granularity' => $this->granularity,
            'kicker' => $this->kicker,
            'comparison' => [
                'from' => $this->previousFrom->toDateString(),
                'to' => $this->previousThrough->toDateString(),
                'label' => $this->label($this->previousFrom, $this->previousThrough),
                'description' => $this->comparisonLabel,
            ],
            'session_filter_available' => $this->allowsSessionFilter(),
        ];
    }

    private function label(CarbonImmutable $from, CarbonImmutable $through): string
    {
        if ($this->granularity === 'month') {
            return $from->format('M Y').' – '.$through->format('M Y');
        }

        return match (true) {
            $from->isSameDay($through) => $from->format('D, M j, Y'),
            $from->isSameYear($through) => $from->format('M j').' – '.$through->format('M j, Y'),
            default => $from->format('M j, Y').' – '.$through->format('M j, Y'),
        };
    }

    private static function span(string $preset, CarbonImmutable $from, CarbonImmutable $through, string $comparison, string $kicker): self
    {
        $length = (int) $from->diffInDays($through) + 1;

        return new self(
            $preset, $from, $through, $from->subDays($length), $from->subDay(),
            $length === 1 ? 'hour' : 'day', $comparison, $kicker,
        );
    }

    /**
     * @param  array{date?: string|null, from?: string|null, to?: string|null}  $filters
     */
    private static function custom(array $filters, CarbonImmutable $today): self
    {
        $from = self::day((string) ($filters['from'] ?? '')) ?? $today;
        $through = self::day((string) ($filters['to'] ?? '')) ?? $today;
        if ($through->lessThan($from)) {
            [$from, $through] = [$through, $from];
        }
        $length = (int) $from->diffInDays($through) + 1;

        return self::span('custom', $from, $through, $length === 1 ? 'previous day' : "previous {$length} days", 'Custom range');
    }
}
