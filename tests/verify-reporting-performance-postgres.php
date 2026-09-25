<?php

/**
 * Opt-in: DB_URL=null php tests/verify-reporting-performance-postgres.php
 *
 * Phase 19 on real PostgreSQL, in one random perf_* schema that is dropped afterwards (the normal development schema is
 * never touched):
 *
 * A. the additive index migration rolls back and re-applies cleanly;
 * B. with realistic volume (audit rows, Orders, notifications) the newest-first queries of the Audit Trail, the
 *    Executive Dashboard, All Branches Transactions / recent transactions and the notification list read their index
 *    backwards instead of sorting the table (EXPLAIN of the exact SQL the application builds);
 * C. SalesAnalytics (PostgreSQL SQL paths) returns the exact Net Sales of thousands of Orders with the same query count
 *    as a small day. No timing is asserted.
 */

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\SalesAnalytics;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

function perfVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
perfVerify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
perfVerify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
perfVerify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
perfVerify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$schema = 'perf_'.bin2hex(random_bytes(8));
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.perf_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('perf_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
perfVerify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

const PERF_INDEXES = [
    'audit_logs_created_at_id_index', 'orders_committed_at_id_index', 'notifications_notifiable_created_at_index',
    'pamamalengke_purchase_items_purchase_index', 'pamamalengke_purchases_store_session_index',
];

/** @return list<string> the Phase 19 indexes present in the isolated schema */
function perfIndexes(string $schema): array
{
    return array_values(array_map(fn (object $row): string => $row->indexname, DB::select(
        'SELECT indexname FROM pg_indexes WHERE schemaname = ? AND indexname IN ('.implode(',', array_fill(0, count(PERF_INDEXES), '?')).') ORDER BY indexname',
        [$schema, ...PERF_INDEXES],
    )));
}

/**
 * The plan nodes of the exact SQL a query builder produces.
 *
 * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, mixed>  $query
 * @return list<array<string, mixed>>
 */
function perfPlan($query): array
{
    $plan = json_decode(DB::selectOne('EXPLAIN (FORMAT JSON) '.$query->toSql(), $query->getBindings())->{'QUERY PLAN'}, true, flags: JSON_THROW_ON_ERROR);
    $nodes = [];
    $walk = function (array $node) use (&$walk, &$nodes): void {
        $nodes[] = $node;
        foreach ($node['Plans'] ?? [] as $child) {
            $walk($child);
        }
    };
    $walk($plan[0]['Plan']);

    return $nodes;
}

/** @param Builder<covariant \Illuminate\Database\Eloquent\Model>|Relation<covariant \Illuminate\Database\Eloquent\Model, covariant \Illuminate\Database\Eloquent\Model, mixed> $query */
function perfUsesIndex($query, string $index, string $label): string
{
    $nodes = perfPlan($query);
    $used = collect($nodes)->first(fn (array $node): bool => ($node['Index Name'] ?? null) === $index);
    perfVerify($used !== null, $label.' must read '.$index.': '.json_encode(array_column($nodes, 'Node Type')));
    perfVerify(collect($nodes)->doesntContain(fn (array $node): bool => in_array($node['Node Type'], ['Sort', 'Incremental Sort'], true)), $label.' must not sort the table.');

    return $label.' → '.$used['Node Type'].' '.($used['Scan Direction'] ?? '').' on '.$index;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;

    /** A. Fresh migration, rollback of the Phase 19 migration only, re-apply. */
    perfVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migration failed.');
    perfVerify(perfIndexes($schema) === collect(PERF_INDEXES)->sort()->values()->all(), 'Every Phase 19 index must exist after migrating.');
    $phase19Steps = count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_25_150406'));
    perfVerify(Artisan::call('migrate:rollback', ['--step' => $phase19Steps, '--force' => true, '--no-interaction' => true]) === 0, 'Rollback failed.');
    perfVerify(perfIndexes($schema) === [], 'Rollback must drop every Phase 19 index.');
    perfVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Re-apply failed.');
    perfVerify(count(perfIndexes($schema)) === count(PERF_INDEXES), 'Re-apply must restore every Phase 19 index.');
    $migration = 'additive index migration: up, rollback ('.$phase19Steps.' step) and re-apply';

    /** B. Realistic volume, then EXPLAIN of the application's own query builders. */
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $cashier = User::factory()->create();
    $admin = User::factory()->create();
    $mainSession = StoreSession::factory()->for($main)->create(['opened_by_user_id' => $cashier->id, 'opened_at' => now()->subHours(3)]);
    $qaveSession = StoreSession::factory()->for($qave)->create(['opened_by_user_id' => $cashier->id, 'opened_at' => now()->subHours(3)]);
    DB::insert(<<<'SQL'
        INSERT INTO audit_logs (id, branch_id, user_id, module, action, auditable_type, auditable_id, after, metadata, created_at)
        SELECT md5('audit' || g)::uuid, CASE WHEN g % 2 = 0 THEN ?::uuid ELSE ?::uuid END, ?::bigint, 'transactions', 'order.paid', 'App\Models\Order',
               g::text, '{}', '{}', now() - (g || ' seconds')::interval
        FROM generate_series(1, 60000) AS g
        SQL, [$main->id, $qave->id, $admin->id]);
    /** A small first day for the query-count baseline (C), then the rest of the volume. */
    $orders = function (int $from, int $to) use ($main, $qave, $mainSession, $qaveSession, $cashier): void {
        DB::insert(<<<'SQL'
            INSERT INTO orders (id, branch_id, store_session_id, order_number, source, order_type, customer_label, commercial_status,
                payment_status, payment_term, kitchen_status, subtotal, total, created_by_user_id, committed_at, completed_at, version, created_at, updated_at)
            SELECT md5('order' || g)::uuid, CASE WHEN g % 3 = 0 THEN ?::uuid ELSE ?::uuid END, CASE WHEN g % 3 = 0 THEN ?::uuid ELSE ?::uuid END, 'PERF-' || g, 'pos',
                   'take_out', 'Perf', 'completed', 'paid', 'immediate', 'done', 50 + (g % 7) * 10, 50 + (g % 7) * 10, ?::bigint,
                   now() - ((g % 10000) || ' seconds')::interval, now(), 1, now(), now()
            FROM generate_series(?::int, ?::int) AS g
            SQL, [$qave->id, $main->id, $qaveSession->id, $mainSession->id, $cashier->id, $from, $to]);
        DB::insert(<<<'SQL'
            INSERT INTO order_items (id, order_id, product_id, product_name_snapshot, unit_price, quantity, line_total, created_at, updated_at)
            SELECT md5('item' || o.id)::uuid, o.id, NULL, 'Tapsilog', o.total, 1, o.total, now(), now()
            FROM orders o WHERE o.order_number LIKE 'PERF-%' AND NOT EXISTS (SELECT 1 FROM order_items i WHERE i.order_id = o.id)
            SQL);
        DB::insert(<<<'SQL'
            INSERT INTO payments (id, branch_id, store_session_id, order_id, method, amount, amount_received, change_amount,
                created_by_user_id, idempotency_key, payment_context, paid_at, created_at, updated_at)
            SELECT md5('payment' || o.id)::uuid, o.branch_id, o.store_session_id, o.id, 'cash', o.total, o.total, 0, ?::bigint, o.id::text || ':cash',
                   'initial', o.committed_at, now(), now()
            FROM orders o WHERE o.order_number LIKE 'PERF-%' AND NOT EXISTS (SELECT 1 FROM payments p WHERE p.order_id = o.id)
            SQL, [$cashier->id]);
    };
    $orders(1, 90);
    DB::insert(<<<'SQL'
        INSERT INTO notifications (id, type, notifiable_type, notifiable_id, data, read_at, created_at, updated_at)
        SELECT md5('notification' || g)::uuid, 'App\Notifications\AdminAlert', 'App\Models\User', CASE WHEN g % 4 = 0 THEN ?::bigint ELSE ?::bigint END,
               '{}', NULL, now() - (g || ' seconds')::interval, now()
        FROM generate_series(1, 40000) AS g
        SQL, [$admin->id, $cashier->id]);
    DB::statement('ANALYZE');

    $analytics = app(SalesAnalytics::class);
    $countQueries = function (callable $run): array {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $result = $run();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $result];
    };
    [$smallCount] = $countQueries(fn () => $analytics->for(null, ['date' => 'today']));

    $orders(91, 30000);
    DB::statement('ANALYZE');

    $plans = [
        perfUsesIndex(AuditLog::query()->latest('created_at')->latest('id')->limit(30)->offset(0), 'audit_logs_created_at_id_index', 'Audit Trail page'),
        perfUsesIndex(AuditLog::query()->latest('created_at')->latest('id')->limit(6), 'audit_logs_created_at_id_index', 'Executive recent audit'),
        perfUsesIndex(Order::query()->whereNotNull('committed_at')->whereIn('commercial_status', ['active', 'completed'])
            ->orderByDesc('committed_at')->orderByDesc('id')->limit(10)->offset(0), 'orders_committed_at_id_index', 'All Branches Transactions'),
        perfUsesIndex($admin->notifications()->reorder()->latest('created_at')->latest('id')->limit(20), 'notifications_notifiable_created_at_index', 'Notification list'),
    ];

    /** C. Exact Net Sales of every PERF Order through the PostgreSQL analytics SQL, with the same query count. */
    [$largeCount, $result] = $countQueries(fn () => $analytics->for(null, ['date' => 'today']));
    $expected = number_format((float) DB::selectOne("SELECT SUM(total) AS total FROM orders WHERE order_number LIKE 'PERF-%'")->total, 2, '.', '');
    perfVerify($result['analytics']['kpis']['sales']['value'] === $expected, 'Net Sales must equal the SQL sum: '.$result['analytics']['kpis']['sales']['value'].' vs '.$expected);
    perfVerify($result['analytics']['kpis']['transactions']['value'] === 30000, 'Every Order is counted once.');
    perfVerify($largeCount === $smallCount, 'Analytics must not add queries per Order: '.$smallCount.' vs '.$largeCount);

    echo 'Reporting performance PostgreSQL verification passed:'.PHP_EOL
        .'  A. '.$migration.PHP_EOL
        .'  B. 60,000 audit rows, 30,000 Orders, 40,000 notifications:'.PHP_EOL
        .'     '.implode(PHP_EOL.'     ', $plans).PHP_EOL
        .'  C. SalesAnalytics All Branches today: Net Sales '.$expected.' over 30,000 Orders equals the SQL sum; '.$largeCount.' queries at 90 and at 30,000 Orders'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
    perfVerify($observer->selectOne('SELECT COUNT(*) AS total FROM pg_namespace WHERE nspname = ?', [$schema])->total === 0, 'Isolated schema was not dropped.');
}
