<?php

/**
 * Opt-in real PostgreSQL verification: php tests/verify-owner-reports-postgres.php
 *
 * Creates and removes only a random phase16a_* schema on a loopback PostgreSQL server. The configured public schema is
 * never migrated, truncated, or written. Proves the Owner Sales & Store Session report aggregates exactly on
 * PostgreSQL (integer cents, grouping, Manila business dates) and matches the single-session reconciliation.
 */

use App\Actions\StoreSessions\CloseStoreSession;
use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Support\BusinessSnapshot;
use App\Support\SalesAnalytics;
use App\Support\StoreSessionReconciliation;
use App\Support\StoreSessionSalesReport;
use App\Support\TransactionHistory;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\StoreCloseScenario;

require dirname(__DIR__).'/vendor/autoload.php';

function verifyPhase16a(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase16aAt(string $manila): void
{
    $moment = CarbonImmutable::parse($manila, 'Asia/Manila')->utc();
    Carbon::setTestNow($moment);
    CarbonImmutable::setTestNow($moment);
}

/** @return array<string, mixed> */
function phase16aReport(?Branch $branch, string $date, ?string $session = null): array
{
    return app(StoreSessionSalesReport::class)->for($branch, array_filter([
        'date' => 'custom', 'from' => $date, 'to' => $date, 'session' => $session,
    ]));
}

function phase16aClose(StoreCloseScenario $scenario, string $closedAtManila): void
{
    phase16aAt($closedAtManila);
    $expected = $scenario->reconciliation()['expected'];
    app(CloseStoreSession::class)->execute($scenario->cashier, $scenario->branch, $scenario->closePayload($expected['cash'], $expected['cashless']));
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verifyPhase16a(! $app->configurationIsCached(), 'Clear cached configuration before local verification.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
verifyPhase16a(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
verifyPhase16a(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
verifyPhase16a(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');

$schema = 'phase16a_'.bin2hex(random_bytes(8));
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase16a_admin' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase16a_admin');
$identity = $observer->selectOne('SELECT current_database() AS database, host(inet_server_addr()) AS host, inet_server_port() AS port');
verifyPhase16a(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

$createdSchema = false;

try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verifyPhase16a(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verifyPhase16a(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');
    verifyPhase16a(Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true, '--no-interaction' => true]) === 0, 'RBAC seed failed.');
    verifyPhase16a(DB::selectOne("SELECT count(*) AS count FROM pg_indexes WHERE schemaname = ? AND tablename = 'orders' AND indexdef LIKE '%(store_session_id%'", [$schema])->count >= 1, 'Orders have no store_session_id-leading index.');
    echo 'INDEX PASS: orders(store_session_id, qr_sequence) leads with store_session_id; no migration is required.'.PHP_EOL;

    /** A: two MAIN sessions on one business date, reported together and individually. */
    phase16aAt('2026-09-23 08:00');
    $main = StoreCloseScenario::create();
    $main->branch->update(['code' => 'MAIN']);
    $main->authorizeVoids();
    $main->product->update(['default_price' => '33.33']);
    $main->done($main->payNow(3, 'cash'), $main->payNow(2, 'split', '16.67'));
    $first = $main->session;
    phase16aClose($main, '2026-09-23 15:00');
    phase16aAt('2026-09-23 17:00');
    $main->session = StoreSession::factory()->for($main->branch)->create(['opened_by_user_id' => $main->cashier->id, 'opened_at' => now()]);
    $corrected = $main->edit($main->payNow(4, 'cash'), 3);
    $main->void($corrected);
    $main->payNow(1, 'cashless');
    $main->expense('12.34', 'cash');

    $all = phase16aReport($main->branch, '2026-09-23');
    verifyPhase16a($all['summary']['net_sales'] === '199.98', 'A: net sales '.$all['summary']['net_sales']);
    verifyPhase16a($all['summary']['orders'] === 3, 'A: orders '.$all['summary']['orders']);
    verifyPhase16a($all['summary']['cash'] === '149.98' && $all['summary']['cashless'] === '50.00', 'A: collections '.json_encode([$all['summary']['cash'], $all['summary']['cashless']]));
    verifyPhase16a($all['summary']['split'] === ['count' => 1, 'total' => '66.66', 'cash' => '49.99', 'cashless' => '16.67'], 'A: split '.json_encode($all['summary']['split']));
    verifyPhase16a($all['summary']['corrections']['total'] === '0.00' && $all['summary']['voids']['reversal'] === '133.32', 'D: correction + void '.json_encode([$all['summary']['corrections'], $all['summary']['voids']]));
    verifyPhase16a($all['summary']['expenses']['total'] === '12.34', 'A: expenses');
    verifyPhase16a(count($all['sessions']) === 2 && count($all['days']) === 1 && $all['days'][0]['sessions'] === 2, 'A: session grouping');
    $onlyFirst = phase16aReport($main->branch, '2026-09-23', $first->id);
    verifyPhase16a($onlyFirst['summary']['net_sales'] === '166.65' && $onlyFirst['sessions'][0]['reconciliation']['source'] === 'closing_snapshot', 'A: first session filter');
    verifyPhase16a(phase16aReport($main->branch, '2026-09-23', $main->session->id)['summary']['net_sales'] === '33.33', 'A: second session filter');
    echo 'A PASS: multiple same-day sessions, exact odd-cent Cash/Cashless/Split and session filtering.'.PHP_EOL;
    echo 'D PASS: a corrected then voided Order nets to zero with one reversal and no second correction.'.PHP_EOL;

    /** B: Manila midnight boundary independent of UTC. */
    phase16aAt('2026-09-23 12:00');
    $boundary = Branch::factory()->create(['code' => 'EDGE']);
    $late = StoreSession::factory()->closed()->for($boundary)->create(['opened_at' => CarbonImmutable::parse('2026-09-22 23:59', 'Asia/Manila')->utc()]);
    $midnight = StoreSession::factory()->for($boundary)->create(['opened_at' => CarbonImmutable::parse('2026-09-23 00:00', 'Asia/Manila')->utc()]);
    verifyPhase16a(array_column(phase16aReport($boundary, '2026-09-22')['sessions'], 'id') === [$late->id], 'B: Sep 22 business date');
    verifyPhase16a(array_column(phase16aReport($boundary, '2026-09-23')['sessions'], 'id') === [$midnight->id], 'B: Sep 23 business date');
    echo 'B PASS: 11:59 PM and 12:00 AM Manila sessions land on their own business dates.'.PHP_EOL;

    /** E: All Branches aggregates every Branch without leakage. */
    phase16aAt('2026-09-23 10:00');
    $qave = StoreCloseScenario::create();
    $qave->branch->update(['code' => 'QAVE']);
    $qave->payNow(50, 'cashless');
    $combined = phase16aReport(null, '2026-09-23');
    verifyPhase16a($combined['summary']['net_sales'] === '5199.98', 'E: combined net sales '.$combined['summary']['net_sales']);
    verifyPhase16a($combined['summary']['cashless'] === '5050.00', 'E: combined cashless');
    verifyPhase16a(phase16aReport($qave->branch, '2026-09-23')['summary']['net_sales'] === '5000.00', 'E: QAVE scope');
    verifyPhase16a(phase16aReport($main->branch, '2026-09-23', $qave->session->id)['session_filter']['ignored'] === true, 'E: cross-Branch session filter');
    echo 'E PASS: All Branches sums MAIN and QAVE; a QAVE session is never reported through MAIN.'.PHP_EOL;

    /** F: batched flows equal the single-session Close Store reconciliation on PostgreSQL. */
    $service = app(StoreSessionReconciliation::class);
    $flows = $service->flows([$main->session->id => $main->branch->id, $qave->session->id => $qave->branch->id]);
    foreach ([$main, $qave] as $scenario) {
        $single = $service->calculate($scenario->branch, $scenario->session->fresh());
        verifyPhase16a($service->expected($service->opening($scenario->session->fresh()), $flows[$scenario->session->id]) === $single['expected'], 'F: expected parity');
        verifyPhase16a($flows[$scenario->session->id]['sales'] === $single['sales'] && $flows[$scenario->session->id]['voids'] === $single['voids'], 'F: flow parity');
    }
    echo 'F PASS: batched session flows match single-session reconciliation.'.PHP_EOL;

    /** G: Phase 16B–D analytics on PostgreSQL agree with the Phase 16A report and use Manila clock hours. */
    $analytics = app(SalesAnalytics::class);
    $day = ['date' => 'custom', 'from' => '2026-09-23', 'to' => '2026-09-23'];
    $mainResult = $analytics->for($main->branch, $day);
    $mainKpis = $mainResult['analytics']['kpis'];
    verifyPhase16a($mainKpis['sales']['value'] === $mainResult['report']['summary']['net_sales'], 'G: analytics sales '.$mainKpis['sales']['value']);
    verifyPhase16a($mainResult['analytics']['collections']['cash'] === '149.98' && $mainResult['analytics']['collections']['cashless'] === '50.00', 'G: analytics collections');
    verifyPhase16a($mainResult['analytics']['collections']['split']['total'] === '66.66', 'G: split explained once');
    $mainHours = array_column($mainResult['analytics']['hours'], null, 'hour');
    verifyPhase16a($mainHours[8]['sales'] === '166.65' && $mainHours[17]['sales'] === '33.33', 'G: Manila hours '.json_encode([$mainHours[8]['sales'] ?? null, $mainHours[17]['sales'] ?? null]));
    verifyPhase16a(! isset($mainHours[0]) && ! isset($mainHours[1]), 'G: UTC hours must not appear');
    echo 'G PASS: analytics sales, collections and Split match Phase 16A; hours are Manila clock hours.'.PHP_EOL;

    /** H: payment-class order filter and order-scoped reconciliation flows on PostgreSQL. */
    $split = $analytics->for($main->branch, [...$day, 'payment_methods' => ['split']])['analytics'];
    verifyPhase16a($split['kpis']['sales']['value'] === '66.66' && $split['kpis']['transactions']['value'] === 1, 'H: split filter sales');
    verifyPhase16a($split['collections']['cash'] === '49.99' && $split['collections']['cashless'] === '16.67', 'H: scoped flows '.json_encode([$split['collections']['cash'], $split['collections']['cashless']]));
    $dineIn = $analytics->for($main->branch, [...$day, 'order_types' => ['dine_in']])['analytics'];
    verifyPhase16a($dineIn['kpis']['sales']['value'] === '0.00' && $dineIn['collections']['total'] === '0.00', 'H: empty filter');
    echo 'H PASS: EXISTS payment classification and order-scoped flows narrow sales and collections together.'.PHP_EOL;

    /** K: the Reports payment method mix counts each paid Order once and the category filter narrows products only. */
    $mix = $mainResult['analytics']['payment_mix'];
    $combined = array_column($mix['combined'], null, 'method');
    $separate = array_column($mix['separate'], null, 'method');
    verifyPhase16a([$combined['cash']['amount'], $combined['cashless']['amount']] === ['149.98', '50.00'], 'K: split parts inside Cash/Cashless '.json_encode($combined));
    verifyPhase16a([$combined['cash']['amount'], $combined['cashless']['amount']] === [$mainResult['analytics']['collections']['cash'], $mainResult['analytics']['collections']['cashless']], 'K: combined view equals reconciled collections');
    verifyPhase16a([$separate['cash']['amount'], $separate['cashless']['amount'], $separate['split']['amount']] === ['99.99', '33.33', '66.66'], 'K: separate view '.json_encode($separate));
    verifyPhase16a($mix['totals'] === ['combined' => '199.98', 'separate' => '199.98'] && $mix['split_pending'] === '0.00', 'K: totals '.json_encode($mix['totals']));
    verifyPhase16a(array_sum(array_column($mix['combined'], 'share')) === 10000 && array_sum(array_column($mix['separate'], 'share')) === 10000, 'K: shares');
    $categoryId = (string) $main->product->category_id;
    $narrowed = $analytics->for($main->branch, [...$day, 'categories' => [$categoryId]])['analytics'];
    $none = $analytics->for($main->branch, [...$day, 'categories' => ['uncategorized']])['analytics'];
    verifyPhase16a(count($narrowed['products']) === 1 && $none['products'] === [], 'K: category narrows products');
    verifyPhase16a($none['kpis'] === $mainResult['analytics']['kpis'] && $none['payment_mix'] === $mainResult['analytics']['payment_mix'] && $none['collections'] === $mainResult['analytics']['collections'], 'K: category leaves money untouched');
    verifyPhase16a(array_column($narrowed['filter_options']['categories'], 'value') === [$categoryId], 'K: category options');
    echo 'K PASS: payment mix puts split parts inside Cash/Cashless (= collections) or shows Split once; category narrows product rows only.'.PHP_EOL;

    /** I: committed-to-ready prep seconds on PostgreSQL. */
    phase16aAt('2026-09-23 11:00');
    $timed = $qave->payNow(1, 'cash');
    phase16aAt('2026-09-23 11:02');
    $qave->kitchenStatus($timed, KitchenStatus::Preparing);
    phase16aAt('2026-09-23 11:05');
    $qave->kitchenStatus($timed, KitchenStatus::Ready);
    $kitchen = $analytics->for($qave->branch, $day)['analytics']['kitchen'];
    verifyPhase16a($kitchen['average_prep_seconds'] === 300 && $kitchen['timed_orders'] === 1, 'I: prep seconds '.json_encode($kitchen['average_prep_seconds']));
    echo 'I PASS: EXTRACT(EPOCH) prep time is exact on PostgreSQL.'.PHP_EOL;

    /** J: live snapshot, recent transactions and All Branches history on PostgreSQL. */
    $snapshot = app(BusinessSnapshot::class);
    $live = $snapshot->kitchen($qave->branch);
    verifyPhase16a($live['ready'] === 1 && $live['kitchen'] === 1 && $live['oldest'] !== null, 'J: kitchen snapshot '.json_encode($live));
    verifyPhase16a(count($snapshot->recentTransactions(null)) === 5, 'J: recent transactions');
    verifyPhase16a($snapshot->inventoryAttention(null)['mode'] === 'branches', 'J: inventory attention');
    $history = app(TransactionHistory::class)->for(null, []);
    verifyPhase16a($history['transactions']->total() === $history['history_total'], 'J: All Branches history');
    echo 'J PASS: Business snapshot and All Branches Transaction History query PostgreSQL.'.PHP_EOL;
    echo 'PASS: PostgreSQL Owner report read parity.'.PHP_EOL;
} finally {
    Carbon::setTestNow();
    CarbonImmutable::setTestNow();
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        verifyPhase16a($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS: removed isolated schema '.$schema.PHP_EOL;
    }
}
