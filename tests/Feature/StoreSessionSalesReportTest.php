<?php

use App\Actions\StoreSessions\CloseStoreSession;
use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Actions\StoreSessions\RecordStoreSessionInventoryAdjustment;
use App\Models\Branch;
use App\Models\OrderAdjustment;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function reportAt(string $manila): void
{
    test()->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila'));
}

function reportUser(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** A Branch with an OPEN Store Session opened at the given Manila time. */
function reportScenario(string $openedAtManila, string $code = 'MAIN'): StoreCloseScenario
{
    reportAt($openedAtManila);
    $scenario = StoreCloseScenario::create();
    $scenario->branch->update(['code' => $code, 'name' => "{$code} Branch"]);
    $scenario->product->update(['default_price' => '100.00']);

    return $scenario;
}

/** Closes the current session through Close Store with an exact count unless overridden. */
function closeReportSession(StoreCloseScenario $scenario, string $closedAtManila, array $overrides = []): StoreSession
{
    reportAt($closedAtManila);
    $expected = $scenario->reconciliation()['expected'];
    app(CloseStoreSession::class)->execute($scenario->cashier, $scenario->branch, $scenario->closePayload($expected['cash'], $expected['cashless'], $overrides));

    return $scenario->session->fresh();
}

function reopenReportSession(StoreCloseScenario $scenario, string $openedAtManila): StoreSession
{
    reportAt($openedAtManila);

    return $scenario->session = StoreSession::factory()->for($scenario->branch)->create([
        'opened_by_user_id' => $scenario->cashier->id,
        'opened_at' => now(),
    ]);
}

/** @param array<string, string> $query */
function salesReport(?Branch $branch, array $query, ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? reportUser())
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.reports', $query));
}

/** @return array<string, string> */
function reportDay(string $date): array
{
    return ['date' => 'custom', 'from' => $date, 'to' => $date];
}

test('multiple Store Sessions on one business date are reported together and individually without double counting', function () {
    $scenario = reportScenario('2026-09-23 08:00');
    $scenario->done($scenario->payNow(45, 'cash'));
    $first = closeReportSession($scenario, '2026-09-23 15:00');
    $second = reopenReportSession($scenario, '2026-09-23 17:00');
    $scenario->done($scenario->payNow(62, 'cash'));
    closeReportSession($scenario, '2026-09-23 23:59');

    $all = salesReport($scenario->branch, reportDay('2026-09-23'))->assertOk();

    expect($all->inertiaProps('report.summary.net_sales'))->toBe('10700.00')
        ->and($all->inertiaProps('report.summary.orders'))->toBe(2)
        ->and($all->inertiaProps('report.summary.sessions'))->toBe(['count' => 2, 'open' => 0])
        ->and(collect($all->inertiaProps('report.session_filter.options'))->pluck('label')->all())
        ->toBe(['Sep 23 · 8:00 AM – 3:00 PM', 'Sep 23 · 5:00 PM – 11:59 PM'])
        ->and($all->inertiaProps('report.days'))->toHaveCount(1)
        ->and($all->inertiaProps('report.days.0'))->toMatchArray(['date' => '2026-09-23', 'sessions' => 2, 'orders' => 2, 'net_sales' => '10700.00']);

    $onlyFirst = salesReport($scenario->branch, [...reportDay('2026-09-23'), 'session' => $first->id]);
    expect($onlyFirst->inertiaProps('report.summary.net_sales'))->toBe('4500.00')
        ->and($onlyFirst->inertiaProps('report.summary.cash'))->toBe('4500.00')
        ->and($onlyFirst->inertiaProps('report.session_filter.selected'))->toBe($first->id)
        ->and(collect($onlyFirst->inertiaProps('report.sessions'))->pluck('id')->all())->toBe([$first->id])
        ->and($onlyFirst->inertiaProps('report.session_filter.options'))->toHaveCount(2);

    expect(salesReport($scenario->branch, [...reportDay('2026-09-23'), 'session' => $second->id])->inertiaProps('report.summary.net_sales'))
        ->toBe('6200.00');
});

test('a session that crosses midnight belongs entirely to the business date it opened on', function () {
    $scenario = reportScenario('2026-09-23 17:00');
    $scenario->done($scenario->payNow(3, 'cash'));
    reportAt('2026-09-24 00:10');
    $scenario->done($scenario->payNow(2, 'cash'));
    $scenario->expense('50.00', 'cash');
    closeReportSession($scenario, '2026-09-24 00:30');

    $opening = salesReport($scenario->branch, reportDay('2026-09-23'));
    expect($opening->inertiaProps('report.summary.net_sales'))->toBe('500.00')
        ->and($opening->inertiaProps('report.summary.expenses.total'))->toBe('50.00')
        ->and($opening->inertiaProps('report.sessions.0'))->toMatchArray([
            'business_date' => '2026-09-23',
            'time_range' => '5:00 PM – Sep 24, 12:30 AM',
            'closed_at' => '2026-09-24T00:30:00+08:00',
        ]);

    $next = salesReport($scenario->branch, reportDay('2026-09-24'));
    expect($next->inertiaProps('report.sessions'))->toBe([])
        ->and($next->inertiaProps('report.summary.net_sales'))->toBe('0.00')
        ->and($next->inertiaProps('report.summary.expenses.total'))->toBe('0.00');
});

test('business dates follow Manila midnight rather than UTC', function () {
    $branch = Branch::factory()->create();
    $late = StoreSession::factory()->closed()->for($branch)->create(['opened_at' => CarbonImmutable::parse('2026-09-22 23:59', 'Asia/Manila')->utc()]);
    $midnight = StoreSession::factory()->for($branch)->create(['opened_at' => CarbonImmutable::parse('2026-09-23 00:00', 'Asia/Manila')->utc()]);

    expect(collect(salesReport($branch, reportDay('2026-09-22'))->inertiaProps('report.sessions'))->pluck('id')->all())->toBe([$late->id])
        ->and(collect(salesReport($branch, reportDay('2026-09-23'))->inertiaProps('report.sessions'))->pluck('id')->all())->toBe([$midnight->id]);
});

test('date presets resolve Manila calendar days', function (string $preset, string $from, string $to) {
    reportAt('2026-09-24 00:30');

    salesReport(null, ['date' => $preset])
        ->assertInertia(fn (Assert $page) => $page
            ->where('report.period.from', $from)
            ->where('report.period.to', $to));
})->with([
    'today' => ['today', '2026-09-24', '2026-09-24'],
    'yesterday' => ['yesterday', '2026-09-23', '2026-09-23'],
    'last 7 days' => ['last_7_days', '2026-09-18', '2026-09-24'],
    'this month' => ['month', '2026-09-01', '2026-09-24'],
]);

test('All Branches aggregates every Branch while a selected Branch reports only its own sessions', function () {
    $main = reportScenario('2026-09-23 09:00', 'MAIN');
    $main->payNow(100, 'cash');
    $qave = reportScenario('2026-09-23 10:00', 'QAVE');
    $qave->payNow(50, 'cashless');

    $all = salesReport(null, reportDay('2026-09-23'));
    expect($all->inertiaProps('report.summary.net_sales'))->toBe('15000.00')
        ->and($all->inertiaProps('report.summary.cash'))->toBe('10000.00')
        ->and($all->inertiaProps('report.summary.cashless'))->toBe('5000.00')
        ->and($all->inertiaProps('report.scope'))->toBeNull()
        ->and(collect($all->inertiaProps('report.sessions'))->pluck('branch.code')->all())->toBe(['MAIN', 'QAVE'])
        ->and(collect($all->inertiaProps('report.session_filter.options'))->pluck('label')->all())
        ->toBe(['Sep 23 · MAIN · 9:00 AM – LIVE', 'Sep 23 · QAVE · 10:00 AM – LIVE']);

    expect(salesReport($main->branch, reportDay('2026-09-23'))->inertiaProps('report.summary.net_sales'))->toBe('10000.00')
        ->and(salesReport($qave->branch, reportDay('2026-09-23'))->inertiaProps('report.summary.net_sales'))->toBe('5000.00');
});

test('Pay Later is a sale before it is a collection', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $order = $scenario->payLater(5);

    $unpaid = salesReport($scenario->branch, reportDay('2026-09-23'));
    expect($unpaid->inertiaProps('report.summary'))->toMatchArray([
        'net_sales' => '500.00', 'orders' => 1, 'cash' => '0.00', 'cashless' => '0.00', 'collected' => '0.00',
    ]);

    $scenario->settle($order, 'cash', '500.00');

    expect(salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary'))->toMatchArray([
        'net_sales' => '500.00', 'orders' => 1, 'cash' => '500.00', 'cashless' => '0.00',
    ]);
});

test('a lower-total correction is reflected once in Net Sales and deducted from its Cash channel', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->edit($scenario->payNow(5, 'cash'), 4);

    $summary = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary');

    expect($summary['net_sales'])->toBe('400.00')
        ->and($summary['cash'])->toBe('400.00')
        ->and($summary['corrections'])->toBe(['total' => '100.00', 'cash' => '100.00', 'cashless' => '0.00', 'unallocated' => '0.00', 'count' => 1]);
});

test('voiding a corrected Order reverses its payments once without subtracting the correction again', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $order = $scenario->edit($scenario->payNow(5, 'cash'), 4);
    $scenario->void($order);

    $summary = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary');

    expect($summary['net_sales'])->toBe('0.00')
        ->and($summary['orders'])->toBe(0)
        ->and($summary['cash'])->toBe('0.00')
        ->and($summary['collected'])->toBe('0.00')
        ->and($summary['corrections']['total'])->toBe('0.00')
        ->and($summary['voids'])->toBe(['count' => 1, 'reversal' => '500.00', 'cash' => '500.00', 'cashless' => '0.00']);
});

test('a voided fully paid Order leaves no sale or collection and reports its reversal', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->void($scenario->payNow(5, 'cash'));
    $scenario->payNow(2, 'cash');

    $summary = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary');

    expect($summary['net_sales'])->toBe('200.00')
        ->and($summary['orders'])->toBe(1)
        ->and($summary['cash'])->toBe('200.00')
        ->and($summary['voids']['reversal'])->toBe('500.00');
});

test('a voided split Order nets both channels to zero', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->void($scenario->payNow(5, 'split', '200.00'));

    $summary = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary');

    expect($summary['cash'])->toBe('0.00')
        ->and($summary['cashless'])->toBe('0.00')
        ->and($summary['voids'])->toMatchArray(['reversal' => '500.00', 'cash' => '300.00', 'cashless' => '200.00']);
});

test('split legs sit inside Cash and Cashless and the split value is informational only', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->payNow(5, 'split', '200.00');

    $summary = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary');

    expect($summary['cash'])->toBe('300.00')
        ->and($summary['cashless'])->toBe('200.00')
        ->and($summary['collected'])->toBe('500.00')
        ->and($summary['split'])->toBe(['count' => 1, 'total' => '500.00', 'cash' => '300.00', 'cashless' => '200.00']);
});

test('expenses count Store Session expenses once and exclude stock-only adjustments', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->expense('100.00', 'cash');
    $scenario->expense('200.00', 'cashless');
    app(RecordStoreSessionExpense::class)->execute($scenario->cashier, $scenario->branch, [
        'idempotency_key' => (string) Str::uuid(), 'description' => 'Restock', 'amount' => '60.00',
        'payment_source' => 'cash', 'note' => null, 'restock' => true, 'product_id' => $scenario->product->id, 'quantity' => 3,
    ]);
    app(RecordStoreSessionInventoryAdjustment::class)->execute($scenario->cashier, $scenario->branch, [
        'idempotency_key' => (string) Str::uuid(), 'reason_code' => 'wastage', 'product_id' => $scenario->product->id, 'quantity' => 2,
    ]);

    expect(salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.summary.expenses'))
        ->toBe(['total' => '360.00', 'cash' => '160.00', 'cashless' => '200.00', 'count' => 3]);
});

test('a closed session reports its persisted close-time reconciliation exactly', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->done($scenario->payNow(5, 'cash'));
    $session = closeReportSession($scenario, '2026-09-23 18:00', [
        'closing_cash_amount' => '1550.00', 'closing_note' => 'Extra coins found',
    ]);

    $row = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.sessions.0');

    expect($row['status'])->toBe('closed')
        ->and($row['result'])->toBe('overage')
        ->and($row['closed_by'])->toBe($scenario->cashier->name)
        ->and($row['closed_at'])->toBe($session->closed_at->setTimezone('Asia/Manila')->toIso8601String())
        ->and($row['reconciliation'])->toBe([
            'source' => 'closing_snapshot',
            'opening' => ['cash' => '1000.00', 'cashless' => '0.00'],
            'expected' => ['cash' => '1500.00', 'cashless' => '0.00'],
            'actual' => ['cash' => '1550.00', 'cashless' => '0.00'],
            'variance' => ['cash' => '50.00', 'cashless' => '0.00'],
            'variance_status' => ['cash' => 'overage', 'cashless' => 'balanced'],
            'closing_note' => 'Extra coins found',
        ]);
});

test('closed session collections come from the persisted snapshot', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->done($scenario->payNow(5, 'cash'));
    $session = closeReportSession($scenario, '2026-09-23 18:00');
    $snapshot = $session->reconciliation_snapshot;
    $snapshot['sales']['cash'] = '480.00';
    StoreSession::query()->whereKey($session->id)->toBase()->update(['reconciliation_snapshot' => json_encode($snapshot)]);

    expect(salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.sessions.0.collections.cash'))->toBe('480.00');
});

test('a legacy closed session without a snapshot falls back to its records and shows missing close values as unavailable', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->closed()->for($branch)->create([
        'opened_at' => CarbonImmutable::parse('2026-09-23 09:00', 'Asia/Manila')->utc(),
        'expected_cash_amount' => null, 'cash_variance' => null,
    ]);

    $row = salesReport($branch, reportDay('2026-09-23'))->inertiaProps('report.sessions.0');

    expect($row['result'])->toBe('unavailable')
        ->and($row['reconciliation']['source'])->toBe('closing_record')
        ->and($row['reconciliation']['expected'])->toBe(['cash' => null, 'cashless' => '0.00'])
        ->and($row['reconciliation']['variance_status'])->toBe(['cash' => null, 'cashless' => 'balanced']);
});

test('a shortage in one channel is never offset by an overage in the other', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->closed()->for($branch)->create([
        'opened_at' => CarbonImmutable::parse('2026-09-23 09:00', 'Asia/Manila')->utc(),
        'cash_variance' => '50.00', 'cashless_variance' => '-50.00', 'expected_cashless_amount' => '-20.00',
    ]);

    $row = salesReport($branch, reportDay('2026-09-23'))->inertiaProps('report.sessions.0');

    expect($row['result'])->toBe('shortage')
        ->and($row['reconciliation']['expected']['cashless'])->toBe('-20.00')
        ->and($row['reconciliation']['variance'])->toBe(['cash' => '50.00', 'cashless' => '-50.00']);
});

test('an open session is live with provisional expected balances and no closing counts', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $scenario->payNow(5, 'cash');
    $scenario->expense('100.00', 'cash');

    $row = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.sessions.0');

    expect($row['status'])->toBe('open')
        ->and($row['result'])->toBe('live')
        ->and($row['time_range'])->toBe('9:00 AM – LIVE')
        ->and($row['closed_at'])->toBeNull()
        ->and($row['orders'])->toBe(1)
        ->and($row['collections'])->toBe(['cash' => '500.00', 'cashless' => '0.00', 'total' => '500.00'])
        ->and($row['reconciliation'])->toMatchArray([
            'source' => 'live',
            'expected' => ['cash' => '1400.00', 'cashless' => '0.00'],
            'actual' => ['cash' => null, 'cashless' => null],
            'variance' => ['cash' => null, 'cashless' => null],
        ]);
    expect($scenario->session->fresh()->status->value)->toBe('open');
});

test('an unallocated correction in an open session is reported as pending and never guessed', function () {
    $scenario = reportScenario('2026-09-23 09:00');
    $order = $scenario->payNow(5, 'split', '200.00');
    OrderAdjustment::factory()->create([
        'order_id' => $order->id, 'branch_id' => $scenario->branch->id, 'store_session_id' => $scenario->session->id,
        'amount' => '50.00', 'cash_amount' => null, 'cashless_amount' => null,
    ]);

    $row = salesReport($scenario->branch, reportDay('2026-09-23'))->inertiaProps('report.sessions.0');

    expect($row['corrections'])->toMatchArray(['total' => '50.00', 'cash' => '0.00', 'cashless' => '0.00', 'unallocated' => '50.00'])
        ->and($row['collections'])->toMatchArray(['cash' => '300.00', 'cashless' => '200.00'])
        ->and($row['reconciliation']['expected'])->toBe(['cash' => null, 'cashless' => null]);
});

test('a zero-activity session still appears under its business date with zero values', function () {
    $branch = Branch::factory()->create();
    StoreSession::factory()->closed()->for($branch)->create(['opened_at' => CarbonImmutable::parse('2026-09-23 08:00', 'Asia/Manila')->utc()]);

    $report = salesReport($branch, reportDay('2026-09-23'));

    expect($report->inertiaProps('report.sessions.0'))->toMatchArray([
        'orders' => 0, 'net_sales' => '0.00', 'collections' => ['cash' => '0.00', 'cashless' => '0.00', 'total' => '0.00'],
    ])
        ->and($report->inertiaProps('report.summary.sessions.count'))->toBe(1);
});

test('a Store Session outside the current Branch scope or date range is never reported', function () {
    $main = reportScenario('2026-09-23 09:00', 'MAIN');
    $main->payNow(1, 'cash');
    $qave = reportScenario('2026-09-23 09:00', 'QAVE');
    $qave->payNow(9, 'cash');
    $earlier = StoreSession::factory()->closed()->for($main->branch)->create(['opened_at' => CarbonImmutable::parse('2026-09-20 09:00', 'Asia/Manila')->utc()]);

    foreach ([$qave->session->id, $earlier->id] as $sessionId) {
        $report = salesReport($main->branch, [...reportDay('2026-09-23'), 'session' => $sessionId]);

        expect($report->inertiaProps('report.session_filter'))->toMatchArray(['selected' => null, 'ignored' => true])
            ->and(collect($report->inertiaProps('report.sessions'))->pluck('id')->all())->toBe([$main->session->id])
            ->and($report->inertiaProps('report.summary.net_sales'))->toBe('100.00');
    }
});

test('custom ranges are validated on the server', function (array $query, string $field, string $message) {
    $this->from(route('workspaces.reports'));

    salesReport(null, $query)->assertRedirectToRoute('workspaces.reports')->assertSessionHasErrors([$field => $message]);
})->with([
    'longer than 31 days' => [['date' => 'custom', 'from' => '2026-08-01', 'to' => '2026-09-01'], 'to', 'Choose a custom range of 31 days or fewer.'],
    'end before start' => [['date' => 'custom', 'from' => '2026-09-23', 'to' => '2026-09-22'], 'to', 'The end date must be on or after the start date.'],
    'missing start' => [['date' => 'custom', 'to' => '2026-09-22'], 'from', 'Choose a start date for the custom range.'],
]);

test('a 31 day custom range is accepted', function () {
    salesReport(null, ['date' => 'custom', 'from' => '2026-08-01', 'to' => '2026-08-31'])->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspaces/reports')->where('report.period.days', 31));
});

test('Owner and Super Admin open the same report page', function (string $role) {
    salesReport(null, [], reportUser($role))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('workspaces/reports'));
})->with(['owner', 'super_admin']);

test('operational roles cannot open reports', function (string $role) {
    $branch = Branch::factory()->create();
    $user = reportUser($role);
    $user->branches()->attach($branch, ['is_active' => true]);

    salesReport($branch, [], $user)->assertForbidden();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('guests and inactive owners are sent to login', function () {
    $this->get(route('workspaces.reports'))->assertRedirectToRoute('login');

    $owner = reportUser();
    $owner->forceFill(['is_active' => false])->save();
    salesReport(null, [], $owner)->assertRedirectToRoute('login');
    $this->assertGuest();
});

test('reporting exposes only a read-only GET route', function () {
    $methods = collect(Route::getRoutes()->getRoutes())
        ->filter(fn ($route): bool => str_contains($route->uri(), 'reports'))
        ->flatMap(fn ($route): array => $route->methods())
        ->unique()->sort()->values()->all();

    expect($methods)->toBe(['GET', 'HEAD']);
});

test('the report query count does not grow with the number of Store Sessions', function () {
    reportAt('2026-09-23 12:00');
    $owner = reportUser();
    $queriesFor = function (int $sessions) use ($owner): int {
        StoreSession::query()->toBase()->delete();
        foreach (range(1, $sessions) as $index) {
            StoreSession::factory()->for(Branch::factory())->create(['opened_at' => now()->subHours($index)]);
        }
        DB::flushQueryLog();
        DB::enableQueryLog();
        salesReport(null, [], $owner)->assertOk();
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    expect($queriesFor(6))->toBe($queriesFor(2));
});
