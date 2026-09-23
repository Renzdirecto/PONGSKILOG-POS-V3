<?php

namespace App\Support;

use App\Enums\CommercialStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read-only Sales & Store Session report shared by the Owner and Super Admin workspaces.
 *
 * Business date is the Asia/Manila calendar date on which a Store Session OPENED; a session that crosses midnight stays
 * entirely under its opening date. Net Sales is sales value: the current final `orders.total` of committed Active or
 * Completed Orders (Pay Later included, drafts, uncommitted QR and voided Orders excluded), so committed edits are never
 * subtracted twice. Cash and Cashless are net collections per channel from `StoreSessionReconciliation`: Payment
 * amounts − allocated corrections − payments of voided Orders. Split legs are already inside those channels, so the
 * split value is explanatory only. Expenses are Store Session expenses only; stock-only inventory adjustments have no
 * financial effect. A CLOSED session reports its persisted close-time snapshot; an OPEN session is computed live and
 * is provisional. Nothing here mutates any record.
 *
 * @phpstan-import-type SessionFlows from StoreSessionReconciliation
 *
 * @phpstan-type Filters array{date?: string|null, from?: string|null, to?: string|null, session?: string|null}
 * @phpstan-type Period array{preset: string, from: CarbonImmutable, through: CarbonImmutable}
 * @phpstan-type SessionRow array{
 *     session: StoreSession,
 *     business_date: string,
 *     orders: int,
 *     net_sales: int,
 *     voided_orders: int,
 *     flows: SessionFlows,
 *     source: 'closing_snapshot'|'closing_record'|'live'
 * }
 */
class StoreSessionSalesReport
{
    public const TIMEZONE = 'Asia/Manila';

    public const PRESETS = ['today', 'yesterday', 'last_7_days', 'month', 'custom'];

    public const MAX_CUSTOM_DAYS = 31;

    public function __construct(private StoreSessionReconciliation $reconciliation) {}

    /**
     * @param  Branch|null  $branch  the authorized global Branch scope, or null for All Branches
     * @param  Filters  $filters  validated report filters
     * @return array<string, mixed>
     */
    public function for(?Branch $branch, array $filters): array
    {
        $period = $this->period($filters);
        $sessions = StoreSession::query()
            ->with(['branch:id,name,code', 'openedBy:id,name', 'closedBy:id,name'])
            ->when($branch !== null, fn (Builder $query) => $query->where('branch_id', $branch?->id))
            ->where('opened_at', '>=', $period['from']->utc())
            ->where('opened_at', '<', $period['through']->addDay()->utc())
            ->orderBy('opened_at')
            ->orderBy('id')
            ->get();

        $requested = is_string($filters['session'] ?? null) ? strtolower($filters['session']) : null;
        $selected = $requested === null ? $sessions : $sessions->where('id', $requested)->values();
        /** A Store Session outside the authorized Branch scope or business-date range is never reported. */
        $ignored = $requested !== null && $selected->isEmpty();
        if ($ignored) {
            $selected = $sessions;
        }
        $rows = $this->rows($selected);

        return [
            'period' => $this->presentPeriod($period),
            'scope' => $branch === null ? null : ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code],
            'session_filter' => [
                'selected' => $ignored ? null : $requested,
                'ignored' => $ignored,
                'options' => $sessions->map(fn (StoreSession $session): array => [
                    'id' => $session->id,
                    'label' => $this->sessionLabel($session, $branch === null),
                    'status' => $session->status->value,
                ])->all(),
            ],
            'summary' => $this->summary($rows),
            'days' => $this->days($rows),
            'sessions' => array_map(fn (array $row): array => $this->presentSession($row), $rows),
        ];
    }

    /**
     * Manila calendar-day boundaries; `through` is the start of the last included day.
     *
     * @param  Filters  $filters
     * @return Period
     */
    private function period(array $filters): array
    {
        $today = CarbonImmutable::now(self::TIMEZONE)->startOfDay();
        $preset = in_array($filters['date'] ?? null, self::PRESETS, true) ? (string) $filters['date'] : 'today';
        [$from, $through] = match ($preset) {
            'yesterday' => [$today->subDay(), $today->subDay()],
            'last_7_days' => [$today->subDays(6), $today],
            'month' => [$today->startOfMonth(), $today],
            'custom' => [$this->day((string) ($filters['from'] ?? '')) ?? $today, $this->day((string) ($filters['to'] ?? '')) ?? $today],
            default => [$today, $today],
        };

        return ['preset' => $preset, 'from' => $from, 'through' => $through];
    }

    private function day(string $date): ?CarbonImmutable
    {
        $day = CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE);

        return $day instanceof CarbonImmutable ? $day : null;
    }

    /**
     * @param  Collection<int, StoreSession>  $sessions
     * @return list<SessionRow>
     */
    private function rows(Collection $sessions): array
    {
        if ($sessions->isEmpty()) {
            return [];
        }
        /** @var array<string, string> $sessionBranches */
        $sessionBranches = $sessions->mapWithKeys(fn (StoreSession $session): array => [$session->id => (string) $session->branch_id])->all();
        $orders = $this->orders($sessionBranches);

        $snapshots = [];
        $live = [];
        foreach ($sessions as $session) {
            $snapshot = $session->status === StoreSessionStatus::Closed ? $this->snapshotFlows($session) : null;
            if ($snapshot === null) {
                $live[$session->id] = $sessionBranches[$session->id];
            } else {
                $snapshots[$session->id] = $snapshot;
            }
        }
        $liveFlows = $this->reconciliation->flows($live);

        return array_values($sessions->map(function (StoreSession $session) use ($orders, $snapshots, $liveFlows): array {
            $counts = $orders[$session->id] ?? ['orders' => 0, 'net_sales' => 0, 'voided_orders' => 0];

            return [
                'session' => $session,
                'business_date' => $this->manila($session->opened_at)->toDateString(),
                ...$counts,
                'flows' => $snapshots[$session->id] ?? $liveFlows[$session->id],
                'source' => match (true) {
                    isset($snapshots[$session->id]) => 'closing_snapshot',
                    $session->status === StoreSessionStatus::Closed => 'closing_record',
                    default => 'live',
                },
            ];
        })->all());
    }

    /**
     * Committed Order counts and Net Sales per Store Session in one grouped query.
     *
     * @param  array<string, string>  $sessionBranches
     * @return array<string, array{orders: int, net_sales: int, voided_orders: int}>
     */
    private function orders(array $sessionBranches): array
    {
        $total = 'CAST(ROUND(orders.total * 100) AS BIGINT)';
        $eligible = "orders.commercial_status IN ('".CommercialStatus::Active->value."', '".CommercialStatus::Completed->value."')";
        $voided = "orders.commercial_status = '".CommercialStatus::Voided->value."'";

        return DB::table('orders')
            ->whereIn('orders.branch_id', array_values(array_unique($sessionBranches)))
            ->whereIn('orders.store_session_id', array_keys($sessionBranches))
            ->whereNotNull('orders.committed_at')
            ->groupBy('orders.store_session_id', 'orders.branch_id')
            ->get([
                DB::raw('orders.store_session_id AS store_session_id'),
                DB::raw('orders.branch_id AS branch_id'),
                DB::raw("SUM(CASE WHEN {$eligible} THEN 1 ELSE 0 END) AS order_count"),
                DB::raw("COALESCE(SUM(CASE WHEN {$eligible} THEN {$total} ELSE 0 END), 0) AS sales_cents"),
                DB::raw("SUM(CASE WHEN {$voided} THEN 1 ELSE 0 END) AS voided_count"),
            ])
            ->filter(fn (object $row): bool => ($sessionBranches[(string) $row->store_session_id] ?? null) === (string) $row->branch_id)
            ->mapWithKeys(fn (object $row): array => [(string) $row->store_session_id => [
                'orders' => (int) $row->order_count,
                'net_sales' => (int) $row->sales_cents,
                'voided_orders' => (int) $row->voided_count,
            ]])
            ->all();
    }

    /**
     * The persisted close-time reconciliation is the historical authority for a CLOSED session. Sessions closed before
     * the snapshot existed fall back to their append-only Payment, Expense and correction records.
     *
     * @return SessionFlows|null
     */
    private function snapshotFlows(StoreSession $session): ?array
    {
        $snapshot = $session->reconciliation_snapshot;
        if (! is_array($snapshot)) {
            return null;
        }
        $sales = $this->snapshotSection($snapshot, 'sales');
        $split = $this->snapshotSection($snapshot, 'split');
        $expenses = $this->snapshotSection($snapshot, 'expenses');
        $corrections = $this->snapshotSection($snapshot, 'corrections');
        $voids = $this->snapshotSection($snapshot, 'voids');
        if ($sales === null || $split === null || $expenses === null || $corrections === null || $voids === null) {
            return null;
        }

        return [
            'sales' => [...$sales, 'total' => $sales['cash'] + $sales['cashless']],
            'split' => $split,
            'expenses' => $expenses,
            'corrections' => [
                'cash' => $corrections['cash'],
                'cashless' => $corrections['cashless'],
                'unallocated' => 0,
                'count' => $corrections['count'],
            ],
            'voids' => $voids,
        ];
    }

    /**
     * @param  array<string, mixed>  $snapshot
     * @return array{cash: int, cashless: int, count: int}|null
     */
    private function snapshotSection(array $snapshot, string $section): ?array
    {
        $cash = data_get($snapshot, "{$section}.cash");
        $cashless = data_get($snapshot, "{$section}.cashless");
        $count = data_get($snapshot, "{$section}.count");
        $decimal = '/\A-?[0-9]{1,12}(?:\.[0-9]{1,2})?\z/';
        if (! is_string($cash) || ! is_string($cashless) || ! is_int($count)
            || preg_match($decimal, $cash) !== 1 || preg_match($decimal, $cashless) !== 1) {
            return null;
        }

        return ['cash' => ExactMoney::signedCents($cash), 'cashless' => ExactMoney::signedCents($cashless), 'count' => $count];
    }

    /**
     * @param  SessionFlows  $flows
     * @return array{cash: int, cashless: int}
     */
    private function collections(array $flows): array
    {
        return [
            'cash' => $flows['sales']['cash'] - $flows['corrections']['cash'] - $flows['voids']['cash'],
            'cashless' => $flows['sales']['cashless'] - $flows['corrections']['cashless'] - $flows['voids']['cashless'],
        ];
    }

    /**
     * @param  list<SessionRow>  $rows
     * @return array<string, mixed>
     */
    private function summary(array $rows): array
    {
        $sum = fn (callable $value): int => (int) array_sum(array_map($value, $rows));
        $cash = $sum(fn (array $row): int => $this->collections($row['flows'])['cash']);
        $cashless = $sum(fn (array $row): int => $this->collections($row['flows'])['cashless']);
        $correction = fn (string $key): int => $sum(fn (array $row): int => $row['flows']['corrections'][$key]);
        $flow = fn (string $section, string $key): int => $sum(fn (array $row): int => $row['flows'][$section][$key]);

        return [
            'net_sales' => $this->money($sum(fn (array $row): int => $row['net_sales'])),
            'orders' => $sum(fn (array $row): int => $row['orders']),
            'cash' => $this->money($cash),
            'cashless' => $this->money($cashless),
            'collected' => $this->money($cash + $cashless),
            'expenses' => [
                'total' => $this->money($flow('expenses', 'cash') + $flow('expenses', 'cashless')),
                'cash' => $this->money($flow('expenses', 'cash')),
                'cashless' => $this->money($flow('expenses', 'cashless')),
                'count' => $flow('expenses', 'count'),
            ],
            'sessions' => [
                'count' => count($rows),
                'open' => count(array_filter($rows, fn (array $row): bool => $row['session']->status === StoreSessionStatus::Open)),
            ],
            'split' => [
                'count' => $flow('split', 'count'),
                'total' => $this->money($flow('split', 'cash') + $flow('split', 'cashless')),
                'cash' => $this->money($flow('split', 'cash')),
                'cashless' => $this->money($flow('split', 'cashless')),
            ],
            'corrections' => [
                'total' => $this->money($correction('cash') + $correction('cashless') + $correction('unallocated')),
                'cash' => $this->money($correction('cash')),
                'cashless' => $this->money($correction('cashless')),
                'unallocated' => $this->money($correction('unallocated')),
                'count' => $correction('count'),
            ],
            'voids' => [
                'count' => $sum(fn (array $row): int => $row['voided_orders']),
                'reversal' => $this->money($flow('voids', 'cash') + $flow('voids', 'cashless')),
                'cash' => $this->money($flow('voids', 'cash')),
                'cashless' => $this->money($flow('voids', 'cashless')),
            ],
        ];
    }

    /**
     * One row per Manila business date that has Store Sessions, oldest first.
     *
     * @param  list<SessionRow>  $rows
     * @return list<array<string, mixed>>
     */
    private function days(array $rows): array
    {
        $days = [];
        foreach ($rows as $row) {
            $collections = $this->collections($row['flows']);
            $day = $days[$row['business_date']] ?? ['sessions' => 0, 'orders' => 0, 'net_sales' => 0, 'cash' => 0, 'cashless' => 0, 'expenses' => 0];
            $days[$row['business_date']] = [
                'sessions' => $day['sessions'] + 1,
                'orders' => $day['orders'] + $row['orders'],
                'net_sales' => $day['net_sales'] + $row['net_sales'],
                'cash' => $day['cash'] + $collections['cash'],
                'cashless' => $day['cashless'] + $collections['cashless'],
                'expenses' => $day['expenses'] + $row['flows']['expenses']['cash'] + $row['flows']['expenses']['cashless'],
            ];
        }
        ksort($days);

        return array_map(fn (string $date, array $day): array => [
            'date' => $date,
            'label' => CarbonImmutable::createFromFormat('!Y-m-d', $date, self::TIMEZONE)?->format('D, M j') ?? $date,
            'sessions' => $day['sessions'],
            'orders' => $day['orders'],
            'net_sales' => $this->money($day['net_sales']),
            'cash' => $this->money($day['cash']),
            'cashless' => $this->money($day['cashless']),
            'expenses' => $this->money($day['expenses']),
        ], array_keys($days), $days);
    }

    /**
     * @param  SessionRow  $row
     * @return array<string, mixed>
     */
    private function presentSession(array $row): array
    {
        $session = $row['session'];
        $flows = $row['flows'];
        $open = $session->status === StoreSessionStatus::Open;
        $opening = $this->reconciliation->opening($session);
        $collections = $this->collections($flows);
        $pendingAllocation = $flows['corrections']['unallocated'] > 0;
        /** An OPEN session's expected balance is provisional and unknown while a correction awaits allocation. */
        $expected = $open
            ? ($pendingAllocation ? ['cash' => null, 'cashless' => null] : $this->reconciliation->expected($opening, $flows))
            : ['cash' => $this->storedCents($session->expected_cash_amount), 'cashless' => $this->storedCents($session->expected_cashless_amount)];
        $variance = $open
            ? ['cash' => null, 'cashless' => null]
            : ['cash' => $this->storedCents($session->cash_variance), 'cashless' => $this->storedCents($session->cashless_variance)];
        $varianceStatus = array_map(fn (?int $cents): ?string => match (true) {
            $cents === null => null,
            $cents < 0 => 'shortage',
            $cents > 0 => 'overage',
            default => 'balanced',
        }, $variance);
        $opened = $this->manila($session->opened_at);
        $closed = $session->closed_at === null ? null : $this->manila($session->closed_at);

        return [
            'id' => $session->id,
            'status' => $session->status->value,
            'result' => $open ? 'live' : $this->result($varianceStatus),
            'branch' => ['id' => $session->branch->id, 'name' => $session->branch->name, 'code' => $session->branch->code],
            'business_date' => $row['business_date'],
            'business_date_label' => $opened->format('M j, Y'),
            'time_range' => $this->timeRange($session),
            'opened_at' => $opened->toIso8601String(),
            'opened_at_label' => $opened->format('M j, Y · g:i A'),
            'closed_at' => $closed?->toIso8601String(),
            'closed_at_label' => $closed?->format('M j, Y · g:i A'),
            'opened_by' => $session->openedBy->name,
            'closed_by' => $session->closedBy?->name,
            'orders' => $row['orders'],
            'net_sales' => $this->money($row['net_sales']),
            'collections' => [
                'cash' => $this->money($collections['cash']),
                'cashless' => $this->money($collections['cashless']),
                'total' => $this->money($collections['cash'] + $collections['cashless']),
            ],
            'split' => [
                'count' => $flows['split']['count'],
                'total' => $this->money($flows['split']['cash'] + $flows['split']['cashless']),
                'cash' => $this->money($flows['split']['cash']),
                'cashless' => $this->money($flows['split']['cashless']),
            ],
            'expenses' => [
                'total' => $this->money($flows['expenses']['cash'] + $flows['expenses']['cashless']),
                'cash' => $this->money($flows['expenses']['cash']),
                'cashless' => $this->money($flows['expenses']['cashless']),
                'count' => $flows['expenses']['count'],
            ],
            'corrections' => [
                'total' => $this->money($flows['corrections']['cash'] + $flows['corrections']['cashless'] + $flows['corrections']['unallocated']),
                'cash' => $this->money($flows['corrections']['cash']),
                'cashless' => $this->money($flows['corrections']['cashless']),
                'unallocated' => $this->money($flows['corrections']['unallocated']),
                'count' => $flows['corrections']['count'],
            ],
            'voids' => [
                'count' => $row['voided_orders'],
                'reversal' => $this->money($flows['voids']['cash'] + $flows['voids']['cashless']),
                'cash' => $this->money($flows['voids']['cash']),
                'cashless' => $this->money($flows['voids']['cashless']),
            ],
            'reconciliation' => [
                'source' => $row['source'],
                'opening' => ['cash' => $this->money($opening['cash']), 'cashless' => $this->money($opening['cashless'])],
                'expected' => array_map(fn (?int $cents): ?string => $cents === null ? null : $this->money($cents), $expected),
                'actual' => [
                    'cash' => $open ? null : $this->storedDecimal($session->closing_cash_amount),
                    'cashless' => $open ? null : $this->storedDecimal($session->closing_cashless_amount),
                ],
                'variance' => array_map(fn (?int $cents): ?string => $cents === null ? null : $this->money($cents), $variance),
                'variance_status' => $varianceStatus,
                'closing_note' => $session->closing_note,
            ],
        ];
    }

    /**
     * Cash and Cashless are judged independently: a shortage in either channel is never offset by the other.
     *
     * @param  array{cash: string|null, cashless: string|null}  $status
     */
    private function result(array $status): string
    {
        return match (true) {
            in_array(null, $status, true) => 'unavailable',
            in_array('shortage', $status, true) => 'shortage',
            in_array('overage', $status, true) => 'overage',
            default => 'balanced',
        };
    }

    /**
     * @param  Period  $period
     * @return array{preset: string, from: string, to: string, label: string, days: int}
     */
    private function presentPeriod(array $period): array
    {
        $from = $period['from'];
        $through = $period['through'];
        $label = match (true) {
            $from->isSameDay($through) => $from->format('D, M j, Y'),
            $from->isSameYear($through) => $from->format('M j').' – '.$through->format('M j, Y'),
            default => $from->format('M j, Y').' – '.$through->format('M j, Y'),
        };

        return [
            'preset' => $period['preset'],
            'from' => $from->toDateString(),
            'to' => $through->toDateString(),
            'label' => $label,
            'days' => (int) $from->diffInDays($through) + 1,
        ];
    }

    private function sessionLabel(StoreSession $session, bool $withBranch): string
    {
        return implode(' · ', array_filter([
            $this->manila($session->opened_at)->format('M j'),
            $withBranch ? $session->branch->code : null,
            $this->timeRange($session),
        ]));
    }

    private function timeRange(StoreSession $session): string
    {
        $opened = $this->manila($session->opened_at);
        if ($session->closed_at === null) {
            return $opened->format('g:i A').' – LIVE';
        }
        $closed = $this->manila($session->closed_at);

        return $opened->format('g:i A').' – '.($closed->isSameDay($opened) ? $closed->format('g:i A') : $closed->format('M j, g:i A'));
    }

    private function manila(CarbonInterface $moment): CarbonImmutable
    {
        return CarbonImmutable::instance($moment)->setTimezone(self::TIMEZONE);
    }

    private function storedCents(?string $amount): ?int
    {
        return $amount === null ? null : ExactMoney::signedCents($amount);
    }

    private function storedDecimal(?string $amount): ?string
    {
        return $amount === null ? null : $this->money(ExactMoney::signedCents($amount));
    }

    private function money(int $cents): string
    {
        return ExactMoney::signedDecimal($cents);
    }
}
