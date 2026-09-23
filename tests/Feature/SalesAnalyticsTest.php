<?php

use App\Actions\StoreSessions\CloseStoreSession;
use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Testing\TestResponse;
use Tests\StoreCloseScenario;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function analyticsAt(string $manila): void
{
    test()->travelTo(CarbonImmutable::parse($manila, 'Asia/Manila'));
}

function analyticsViewer(string $role = 'owner'): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

/** A Branch with an OPEN Store Session opened at the given Manila time and a 100.00 product. */
function analyticsScenario(string $openedAtManila, string $code = 'MAIN'): StoreCloseScenario
{
    analyticsAt($openedAtManila);
    $scenario = StoreCloseScenario::create();
    $scenario->branch->update(['code' => $code, 'name' => "{$code} Branch"]);
    $scenario->product->update(['name' => 'Tapsilog', 'default_price' => '100.00']);

    return $scenario;
}

function analyticsClose(StoreCloseScenario $scenario, string $closedAtManila): StoreSession
{
    analyticsAt($closedAtManila);
    $expected = $scenario->reconciliation()['expected'];
    app(CloseStoreSession::class)->execute($scenario->cashier, $scenario->branch, $scenario->closePayload($expected['cash'], $expected['cashless']));

    return $scenario->session->fresh();
}

function analyticsReopen(StoreCloseScenario $scenario, string $openedAtManila): StoreSession
{
    analyticsAt($openedAtManila);

    return $scenario->session = StoreSession::factory()->for($scenario->branch)->create([
        'opened_by_user_id' => $scenario->cashier->id,
        'opened_at' => now(),
    ]);
}

/** @param array<string, mixed> $query */
function analyticsReport(?Branch $branch, array $query, ?User $user = null): TestResponse
{
    return test()->actingAs($user ?? analyticsViewer())
        ->withSession($branch === null ? [] : [ActiveBranchContext::SESSION_KEY => $branch->id])
        ->get(route('workspaces.reports', $query));
}

/** @return array<string, string> */
function analyticsDay(string $date): array
{
    return ['date' => 'custom', 'from' => $date, 'to' => $date];
}

test('sales count committed orders once with split legs inside cash and cashless and tendered cash excluded', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->payNow(2, 'cash', null, ['cash_received' => '500.00']);
    $scenario->payNow(3, 'split', '120.00');
    $scenario->payNow(1, 'cashless');

    $analytics = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->assertOk()->inertiaProps('analytics');

    expect($analytics['kpis']['sales']['value'])->toBe('600.00')
        ->and($analytics['kpis']['transactions']['value'])->toBe(3)
        ->and($analytics['kpis']['items']['value'])->toBe(6)
        ->and($analytics['kpis']['average_order']['value'])->toBe('200.00')
        ->and($analytics['kpis']['cashless_share']['value'])->toBe(3667)
        ->and($analytics['collections'])->toMatchArray([
            'cash' => '380.00',
            'cashless' => '220.00',
            'total' => '600.00',
            'orders' => ['cash' => 1, 'cashless' => 1, 'split' => 1, 'unpaid' => 0],
            'split' => ['count' => 1, 'total' => '300.00', 'cash' => '180.00', 'cashless' => '120.00'],
        ]);
});

test('voided orders contribute nothing and a correction reduces its channel exactly once', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->payNow(3, 'cash');
    $voided = $scenario->payNow(2, 'cash');
    $edited = $scenario->payNow(4, 'cash');
    $scenario->void($voided);
    $scenario->edit($edited, 2);

    $response = analyticsReport($scenario->branch, analyticsDay('2026-09-23'));
    $analytics = $response->inertiaProps('analytics');

    expect($analytics['kpis']['sales']['value'])->toBe('500.00')
        ->and($analytics['kpis']['transactions']['value'])->toBe(2)
        ->and($analytics['kpis']['items']['value'])->toBe(5)
        ->and($analytics['collections']['cash'])->toBe('500.00')
        ->and($analytics['collections']['cash'])->toBe($response->inertiaProps('report.summary.cash'))
        ->and($analytics['kpis']['sales']['value'])->toBe($response->inertiaProps('report.summary.net_sales'));
});

test('an all-voided period shows zero sales without inventing figures', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->void($scenario->payNow(2, 'cash'));

    $analytics = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics');

    expect($analytics['kpis']['sales']['value'])->toBe('0.00')
        ->and($analytics['kpis']['transactions']['value'])->toBe(0)
        ->and($analytics['kpis']['average_order']['value'])->toBeNull()
        ->and($analytics['kpis']['cashless_share']['value'])->toBeNull()
        ->and($analytics['collections']['total'])->toBe('0.00')
        ->and($analytics['peak_hour'])->toBeNull()
        ->and($analytics['products'])->toBe([])
        ->and(collect($analytics['highlights'])->firstWhere('label', 'Top product')['value'])->toBe('—');
});

test('peak sales hours and hourly trend buckets use Manila clock hours', function () {
    $scenario = analyticsScenario('2026-09-23 08:00');
    analyticsAt('2026-09-23 12:40');
    $scenario->payNow(2, 'cash');
    analyticsAt('2026-09-23 18:05');
    $scenario->payNow(1, 'cash');

    $analytics = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics');
    $hours = collect($analytics['hours'])->keyBy('hour');
    $trend = collect($analytics['trend']['buckets'])->keyBy('key');

    expect($analytics['trend']['granularity'])->toBe('hour')
        ->and($hours[12]['sales'])->toBe('200.00')
        ->and($hours[18]['sales'])->toBe('100.00')
        ->and($hours->has(4))->toBeFalse()
        ->and($trend['12']['sales'])->toBe('200.00')
        ->and($analytics['peak_hour'])->toMatchArray(['hour' => 12, 'label' => '12 PM – 1 PM', 'sales' => '200.00', 'transactions' => 1]);
});

test('all sessions of a business date combine while one session drills in without a comparison', function () {
    $scenario = analyticsScenario('2026-09-23 08:00');
    analyticsAt('2026-09-23 10:00');
    $scenario->done($scenario->payNow(1, 'cash'));
    $first = analyticsClose($scenario, '2026-09-23 15:00');
    analyticsReopen($scenario, '2026-09-23 17:00');
    analyticsAt('2026-09-23 20:15');
    $scenario->payNow(2, 'cashless');

    $all = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics');
    $trend = collect($all['trend']['buckets'])->keyBy('key');
    $one = analyticsReport($scenario->branch, [...analyticsDay('2026-09-23'), 'session' => $first->id])->inertiaProps('analytics');

    expect($all['kpis']['sales']['value'])->toBe('300.00')
        ->and($all['kpis']['transactions']['value'])->toBe(2)
        ->and($trend['10']['sales'])->toBe('100.00')
        ->and($trend['20']['sales'])->toBe('200.00')
        ->and($all['comparison']['available'])->toBeTrue()
        ->and($one['kpis']['sales']['value'])->toBe('100.00')
        ->and($one['collections']['cash'])->toBe('100.00')
        ->and($one['collections']['cashless'])->toBe('0.00')
        ->and($one['comparison']['available'])->toBeFalse()
        ->and($one['kpis']['sales']['delta'])->toBeNull();
});

test('the previous period is the immediately preceding equal period with aligned trend buckets', function () {
    $scenario = analyticsScenario('2026-09-22 09:00');
    analyticsAt('2026-09-22 10:00');
    $scenario->done($scenario->payNow(1, 'cash'));
    analyticsClose($scenario, '2026-09-22 18:00');
    analyticsReopen($scenario, '2026-09-23 09:00');
    analyticsAt('2026-09-23 10:30');
    $scenario->payNow(2, 'cash');

    $day = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics');
    $week = analyticsReport($scenario->branch, ['date' => 'last_7_days'])->inertiaProps('analytics');

    expect($day['comparison']['description'])->toBe('previous day')
        ->and($day['kpis']['sales']['previous'])->toBe('100.00')
        ->and($day['kpis']['sales']['delta'])->toBe(['direction' => 'up', 'text' => '+100.0%', 'tone' => 'good'])
        ->and($day['kpis']['transactions']['delta']['direction'])->toBe('flat')
        ->and(collect($day['trend']['buckets'])->firstWhere('key', '10')['previous']['sales'])->toBe('100.00')
        ->and($week['trend']['granularity'])->toBe('day')
        ->and(collect($week['trend']['buckets'])->pluck('key')->all())->toBe(['2026-09-17', '2026-09-18', '2026-09-19', '2026-09-20', '2026-09-21', '2026-09-22', '2026-09-23'])
        ->and(collect($week['trend']['buckets'])->firstWhere('key', '2026-09-22')['sales'])->toBe('100.00')
        ->and($week['kpis']['sales']['previous'])->toBe('0.00')
        ->and($week['kpis']['sales']['delta']['text'])->toBe('New');
});

test('a twelve-month report groups by calendar month and compares with the previous year', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->payNow(2, 'cash');

    $response = analyticsReport($scenario->branch, ['date' => 'last_12_months']);
    $analytics = $response->inertiaProps('analytics');

    expect($analytics['trend']['granularity'])->toBe('month')
        ->and($analytics['trend']['buckets'])->toHaveCount(12)
        ->and($analytics['trend']['buckets'][0]['key'])->toBe('2025-10')
        ->and($analytics['trend']['buckets'][11])->toMatchArray(['key' => '2026-09', 'sales' => '200.00'])
        ->and($analytics['comparison']['description'])->toBe('previous year')
        ->and($response->inertiaProps('report.session_filter.available'))->toBeFalse();
});

test('order filters narrow every figure including collections while store session reconciliation stays whole', function (array $filters, array $expected) {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->payNow(2, 'cash', null, ['order_type' => 'dine_in']);
    $scenario->payNow(3, 'split', '100.00');

    $response = analyticsReport($scenario->branch, [...analyticsDay('2026-09-23'), ...$filters]);
    $analytics = $response->inertiaProps('analytics');

    expect($analytics['filters']['active'])->toBeTrue()
        ->and($analytics['kpis']['sales']['value'])->toBe($expected['sales'])
        ->and($analytics['kpis']['transactions']['value'])->toBe(1)
        ->and($analytics['collections']['cash'])->toBe($expected['cash'])
        ->and($analytics['collections']['cashless'])->toBe($expected['cashless'])
        ->and($analytics['collections']['split']['count'])->toBe($expected['split'])
        ->and(collect($analytics['products'])->sum('quantity'))->toBe($expected['quantity'])
        ->and(collect($analytics['order_types'])->firstWhere('type', 'dine_in')['sales'])->toBe($expected['dine_in'])
        ->and(collect($analytics['cashiers'])->sum('transactions'))->toBe(1)
        ->and($response->inertiaProps('report.summary.net_sales'))->toBe('500.00');
})->with([
    'dine in only' => [['order_types' => ['dine_in']], ['sales' => '200.00', 'cash' => '200.00', 'cashless' => '0.00', 'split' => 0, 'quantity' => 2, 'dine_in' => '200.00']],
    'split only' => [['payment_methods' => ['split']], ['sales' => '300.00', 'cash' => '200.00', 'cashless' => '100.00', 'split' => 1, 'quantity' => 3, 'dine_in' => '0.00']],
]);

test('the cashier filter keeps only orders attributed to that cashier and lists every cashier of the period', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->payNow(2, 'cash');
    $main = $scenario->cashier;
    $other = $scenario->user('cashier');
    $other->update(['name' => 'Bea Other']);
    $scenario->cashier = $other;
    $scenario->payNow(1, 'cashless');
    $scenario->cashier = $main;

    $analytics = analyticsReport($scenario->branch, [...analyticsDay('2026-09-23'), 'cashiers' => [$other->id]])->inertiaProps('analytics');

    expect($analytics['kpis']['sales']['value'])->toBe('100.00')
        ->and($analytics['collections']['cashless'])->toBe('100.00')
        ->and($analytics['collections']['cash'])->toBe('0.00')
        ->and($analytics['cashiers'])->toHaveCount(1)
        ->and($analytics['cashiers'][0])->toMatchArray(['name' => 'Bea Other', 'transactions' => 1, 'cashless' => '100.00'])
        ->and(collect($analytics['filter_options']['cashiers'])->pluck('value')->sort()->values()->all())->toBe(collect([$main->id, $other->id])->sort()->values()->all());
});

test('categories and products come from immutable order item snapshots', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    $scenario->product->category->update(['name' => 'Silog']);
    $drink = Product::factory()->create(['name' => 'Iced Tea', 'default_price' => '50.00']);
    $drink->category->update(['name' => 'Drinks']);
    BranchProduct::factory()->for($scenario->branch)->for($drink)->create(['tracks_inventory' => false]);
    $scenario->payNow(2, 'cash');
    $scenario->payNow(3, 'cash', null, [
        'items' => [['product_id' => $drink->id, 'quantity' => 3, 'notes' => null, 'modifiers' => []]],
        'cash_received' => '200.00',
    ]);
    $drink->update(['name' => 'Lemon Tea', 'default_price' => '80.00']);

    $analytics = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics');

    expect($analytics['kpis']['sales']['value'])->toBe('350.00')
        ->and(collect($analytics['categories'])->map(fn (array $row) => [$row['name'], $row['sales'], $row['items'], $row['share']])->all())
        ->toBe([['Silog', '200.00', 2, 5714], ['Drinks', '150.00', 3, 4286]])
        ->and(collect($analytics['products'])->map(fn (array $row) => [$row['name'], $row['category'], $row['quantity'], $row['orders'], $row['sales'], $row['average_price']])->all())
        ->toBe([['Tapsilog', 'Silog', 2, 1, '200.00', '100.00'], ['Iced Tea', 'Drinks', 3, 1, '150.00', '50.00']])
        ->and(collect($analytics['highlights'])->firstWhere('label', 'Top category')['value'])->toBe('Silog');
});

test('kitchen performance measures committed to ready time and completed orders', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');
    analyticsAt('2026-09-23 10:00');
    $timed = $scenario->payNow(1, 'cash');
    analyticsAt('2026-09-23 10:03');
    $scenario->kitchenStatus($timed, KitchenStatus::Preparing);
    analyticsAt('2026-09-23 10:08');
    $scenario->kitchenStatus($timed, KitchenStatus::Ready);
    analyticsAt('2026-09-23 10:10');
    $scenario->kitchenStatus($timed, KitchenStatus::Done);

    $kitchen = analyticsReport($scenario->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics.kitchen');

    expect($kitchen['completed'])->toBe(1)
        ->and($kitchen['average_prep_seconds'])->toBe(480)
        ->and($kitchen['timed_orders'])->toBe(1)
        ->and(collect($kitchen['by_hour'])->firstWhere('hour', 10)['average_seconds'])->toBe(480)
        ->and($kitchen['fastest']['full'])->toBe('10 AM – 11 AM');
});

test('all branches aggregate authorized branches with a factual comparison while a selected branch stays isolated', function () {
    $alpha = analyticsScenario('2026-09-23 09:00', 'ALPHA');
    $alpha->payNow(2, 'cash');
    $bravo = analyticsScenario('2026-09-23 09:00', 'BRAVO');
    $bravo->payNow(1, 'cashless');
    $bravo->expense('30.00', 'cash');

    $all = analyticsReport(null, analyticsDay('2026-09-23'))->inertiaProps('analytics');
    $selected = analyticsReport($alpha->branch, analyticsDay('2026-09-23'))->inertiaProps('analytics');

    expect($all['kpis']['sales']['value'])->toBe('300.00')
        ->and(collect($all['branches'])->map(fn (array $row) => [$row['branch']['code'], $row['sales'], $row['orders'], $row['cash'], $row['cashless'], $row['expenses']])->all())
        ->toBe([['ALPHA', '200.00', 1, '200.00', '0.00', '0.00'], ['BRAVO', '100.00', 1, '0.00', '100.00', '30.00']])
        ->and($selected['kpis']['sales']['value'])->toBe('200.00')
        ->and($selected['collections']['cashless'])->toBe('0.00')
        ->and($selected['branches'])->toBeNull();
});

test('report filters reject values outside the supported options', function () {
    $scenario = analyticsScenario('2026-09-23 09:00');

    analyticsReport($scenario->branch, [...analyticsDay('2026-09-23'), 'order_types' => ['delivery']])
        ->assertSessionHasErrors(['order_types.0']);
});

test('the csv export contains the same scoped and filtered figures and never another branch', function () {
    $alpha = analyticsScenario('2026-09-23 09:00', 'ALPHA');
    $alpha->payNow(2, 'cash', null, ['order_type' => 'dine_in']);
    $alpha->payNow(4, 'cash');
    $bravo = analyticsScenario('2026-09-23 09:00', 'BRAVO');
    $bravo->payNow(1, 'cashless');
    $owner = analyticsViewer();

    $scoped = $this->actingAs($owner)
        ->withSession([ActiveBranchContext::SESSION_KEY => $alpha->branch->id])
        ->get(route('workspaces.reports.export', [...analyticsDay('2026-09-23'), 'order_types' => ['dine_in']]))
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')
        ->assertDownload('pongskilog-report-alpha-2026-09-23.csv');
    $this->flushSession();
    $all = $this->actingAs($owner)
        ->get(route('workspaces.reports.export', analyticsDay('2026-09-23')))
        ->assertOk();

    $rows = fn (TestResponse $response): array => array_map(
        fn (string $line): array => str_getcsv($line, escape: ''),
        explode("\n", trim(ltrim((string) $response->getContent(), "\u{FEFF}"))),
    );

    expect($rows($scoped))
        ->toContain(['Branch scope', 'ALPHA · ALPHA Branch'])
        ->toContain(['Active filters', 'Order type: Dine in'])
        ->toContain(['Total sales', '200.00', '0.00'])
        ->and((string) $scoped->getContent())->not->toContain('BRAVO')
        ->and($rows($all))
        ->toContain(['Branch scope', 'All Branches'])
        ->toContain(['Total sales', '700.00', '0.00'])
        ->toContain(['BRAVO · BRAVO Branch', '1', '1', '100.00', '0.00', '100.00', '0.00']);
});

test('staff without business reporting cannot export reports', function (string $role) {
    $scenario = analyticsScenario('2026-09-23 09:00');

    $this->actingAs($scenario->user($role))
        ->get(route('workspaces.reports.export', analyticsDay('2026-09-23')))
        ->assertForbidden();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);
