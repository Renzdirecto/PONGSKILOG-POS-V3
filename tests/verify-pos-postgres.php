<?php

/**
 * Opt-in: DB_URL=null php tests/verify-pos-postgres.php with local DB_* settings.
 * Independent workers need committed fixtures, so this follows the existing
 * PostgreSQL harnesses outside Pest's RefreshDatabase suite. Only a random
 * phase5_* schema is migrated and removed; the public schema is never written.
 */

use App\Actions\Orders\CreatePosDraftOrder;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Order;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array<string, mixed> */
function draftPayload(string $productId): array
{
    return ['order_type' => 'take_out', 'customer_label' => 'Isolated PostgreSQL acceptance',
        'items' => [['product_id' => $productId, 'quantity' => 2, 'modifiers' => [], 'notes' => 'Retain this note']]];
}

function verifyUsableConnection(): void
{
    verify(DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction(), 'Transaction did not close.');
    verify(DB::selectOne('SELECT 1 AS usable')->usable === 1, 'Connection is unusable.');
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});
$connection = config('database.connections.pgsql');
verify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
verify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
verify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');
verify(ctype_digit((string) $connection['port']), 'A single numeric local port is required.');
$worker = ($argv[1] ?? null) === '--worker';
$schema = $worker ? ($argv[2] ?? '') : 'phase5_'.bin2hex(random_bytes(8));
verify(preg_match('/\Aphase5_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase5_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'broadcasting.default' => 'null', 'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase5_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host, inet_server_port() AS port');
verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'Server must report a loopback address.');

if ($worker) {
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Worker schema isolation failed.');
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '20s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.$argv[3]]);
    $user = User::query()->findOrFail($argv[3]);
    $branch = Branch::query()->findOrFail($argv[4]);
    $orders = [];
    for ($index = 0; $index < 5; $index++) {
        $order = app(CreatePosDraftOrder::class)->execute($user, $branch, draftPayload($argv[5]));
        $orders[] = ['number' => $order->order_number, 'reference' => $order->reference_number];
        verifyUsableConnection();
    }
    echo json_encode(['pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, 'orders' => $orders], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$processes = [];
$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Isolated fresh migration failed.');
    (new RbacSeeder)->run();
    echo 'FRESH MIGRATION PASS '.$schema.PHP_EOL;

    $columns = collect(DB::select('SELECT table_name, column_name, data_type, numeric_precision, numeric_scale, column_default FROM information_schema.columns WHERE table_schema = ?', [$schema]));
    foreach (['branch_tables', 'orders', 'order_items', 'order_item_modifiers'] as $table) {
        verify($columns->first(fn ($column): bool => $column->table_name === $table && $column->column_name === 'id')->data_type === 'uuid', $table.' ID must be UUID.');
    }
    foreach (['orders' => ['subtotal', 'total'], 'order_items' => ['unit_price', 'line_total'], 'order_item_modifiers' => ['price_delta_snapshot']] as $table => $names) {
        foreach ($names as $name) {
            $column = $columns->first(fn ($column): bool => $column->table_name === $table && $column->column_name === $name);
            verify($column->data_type === 'numeric' && $column->numeric_precision === 14 && $column->numeric_scale === 2, $table.'.'.$name.' must be numeric(14,2).');
        }
    }
    verify(in_array($columns->first(fn ($column): bool => $column->table_name === 'orders' && $column->column_name === 'version')->column_default, ['1', "'1'::bigint"], true), 'Version default must be 1.');
    verify($columns->contains(fn ($column): bool => $column->table_name === 'orders' && $column->column_name === 'reference_number'), 'Missing orders.reference_number.');
    verify($columns->contains(fn ($column): bool => $column->table_name === 'order_number_counters' && $column->column_name === 'next_number' && $column->data_type === 'bigint'), 'Missing bigint order counter.');
    $constraints = collect(DB::select('SELECT c.conname, c.contype, c.confdeltype, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = ?', [$schema]))->keyBy('conname');
    verify(str_contains($constraints['orders_branch_id_order_number_unique']->definition, 'UNIQUE (branch_id, order_number)'), 'Missing branch/order uniqueness.');
    verify($constraints['orders_reference_number_unique']->contype === 'u', 'Missing global reference uniqueness.');
    verify($constraints['order_number_counters_branch_id_foreign']->confdeltype === 'r', 'Counter branch FK must restrict deletion.');
    verify($constraints['order_number_counters_next_number_check']->contype === 'c', 'Missing positive counter CHECK.');
    foreach (['orders_subtotal_check', 'orders_total_check', 'orders_version_check', 'order_items_quantity_check', 'order_items_unit_price_check', 'order_items_line_total_check', 'order_item_modifiers_quantity_check', 'order_item_modifiers_price_delta_snapshot_check'] as $name) {
        verify(isset($constraints[$name]) && $constraints[$name]->contype === 'c', 'Missing CHECK '.$name);
    }
    foreach (['orders_branch_id_foreign', 'orders_branch_table_id_foreign', 'orders_store_session_id_foreign', 'orders_created_by_user_id_foreign', 'order_items_order_id_foreign', 'order_item_modifiers_order_item_id_foreign'] as $name) {
        verify($constraints[$name]->confdeltype === 'r', 'Historical FK must restrict deletion: '.$name);
    }
    foreach (['order_items_product_id_foreign', 'order_item_modifiers_modifier_option_id_foreign'] as $name) {
        verify($constraints[$name]->confdeltype === 'n', 'Catalog FK must null on deletion: '.$name);
    }
    $indexes = array_column(DB::select('SELECT indexname FROM pg_indexes WHERE schemaname = ?', [$schema]), 'indexname');
    foreach (['orders_order_number_index', 'orders_branch_id_created_at_index', 'orders_branch_id_commercial_status_created_at_index', 'orders_branch_id_payment_status_created_at_index', 'orders_branch_id_kitchen_status_created_at_index', 'orders_branch_id_archived_at_index', 'order_items_order_id_index', 'order_item_modifiers_order_item_id_index'] as $index) {
        verify(in_array($index, $indexes, true), 'Missing index '.$index);
    }
    echo 'SCHEMA PASS: identifiers, counter, UUID, numeric(14,2), CHECKs, unique, indexes, FK delete rules, version default.'.PHP_EOL;

    $branch = Branch::factory()->create();
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create(['default_price' => '95.00']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 10, 'version' => 4]);
    $originalBalance = $balance->refresh()->getAttributes();
    $cashiers = User::factory()->count(4)->create();
    foreach ($cashiers as $cashier) {
        $cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
        $cashier->branches()->attach($branch, ['is_active' => true]);
    }
    DB::beginTransaction();
    Branch::query()->whereKey($branch->id)->lockForUpdate()->sole();
    foreach ($cashiers as $cashier) {
        $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema, (string) $cashier->id, $branch->id, $product->id], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 30);
        $processes[] = $process;
        $process->start();
    }
    $deadline = hrtime(true) + 10_000_000_000;
    do {
        $waiting = $observer->select("SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND query LIKE 'select%branches%for update' AND cardinality(pg_blocking_pids(pid)) > 0", [$schema.'_%']);
        if (count($waiting) === 4) {
            break;
        }
        foreach ($processes as $process) {
            verify($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verify(hrtime(true) < $deadline, 'Four concurrent action calls did not reach the branch lock.');
        usleep(10_000);
    } while (true);
    DB::commit();
    $results = [];
    foreach ($processes as $process) {
        verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    verify(count(array_unique(array_column($results, 'pid'))) === 4, 'Workers must use independent connections.');
    verify(Order::query()->count() === 20 && Order::query()->distinct()->count('order_number') === 20, 'Concurrent drafts must persist unique numbers.');
    $numbers = Order::query()->where('branch_id', $branch->id)->pluck('order_number')->map(fn (string $number): int => (int) $number)->sort()->values()->all();
    verify($numbers === range(1001, 1020), 'Concurrent allocation must produce the serialized numeric range.');
    verify(Order::query()->where('branch_id', $branch->id)->whereNull('reference_number')->doesntExist(), 'New drafts require references.');
    verify(Order::query()->where('branch_id', $branch->id)->get()->every(fn (Order $order): bool => preg_match('/\A'.preg_quote($branch->code, '/').'-\d{6}-'.$order->order_number.'\z/', (string) $order->reference_number) === 1), 'Reference format must bind branch, business date and number.');
    verify((int) DB::table('order_number_counters')->where('branch_id', $branch->id)->value('next_number') === 1021, 'Counter did not advance exactly once per order.');
    echo 'CONCURRENCY PASS: 4 observed overlapping connections allocated numeric 1001-1020 with unique immutable references; all worker connections reusable.'.PHP_EOL;

    DB::table('order_number_counters')->where('branch_id', $branch->id)->update(['next_number' => 2000]);
    Order::factory()->for($branch)->create(['order_number' => '2000', 'reference_number' => null]);
    $skipped = app(CreatePosDraftOrder::class)->execute($cashiers->first(), $branch, draftPayload($product->id));
    verify($skipped->order_number === '2001' && (int) DB::table('order_number_counters')->where('branch_id', $branch->id)->value('next_number') === 2002, 'Historical numeric collision was not skipped.');
    verify(Order::query()->where('order_number', '2000')->whereNull('reference_number')->exists(), 'Legacy identity was rewritten.');
    verifyUsableConnection();
    echo 'LEGACY PASS: historical numeric collision skipped without rewriting the legacy row.'.PHP_EOL;
    verify($balance->fresh()->getAttributes() === $originalBalance, 'Drafts changed inventory or version.');
    verify(DB::table('inventory_movements')->count() === 0, 'Drafts created inventory movements.');
    verify(Order::query()->where('source', 'pos')->where('commercial_status', 'draft')->where('payment_status', 'unpaid')->where('kitchen_status', 'not_sent')->whereNull('payment_term')->whereNull('committed_at')->whereNull('store_session_id')->count() === 22, 'Draft state boundary changed.');
    verify(DB::table('order_items')->count() === 21, 'Partial or missing item rows.');
    echo 'PASS: PostgreSQL Phase 5 acceptance; no inventory or operational side effects.'.PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    foreach ($processes as $process) {
        if ($process->isRunning()) {
            $process->stop(0);
        }
    }
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        verify($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS '.$schema.PHP_EOL;
    }
}
