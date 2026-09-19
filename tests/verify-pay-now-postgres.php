<?php

/**
 * Opt-in: DB_URL=null php tests/verify-pay-now-postgres.php with local DB_* settings.
 * Independent workers need committed fixtures, so this follows the existing
 * PostgreSQL harnesses outside Pest's RefreshDatabase suite. Only a random
 * phase6_* schema is migrated and removed; the public schema is never written.
 */

use App\Actions\Orders\PayNowOrder;
use App\Events\KitchenTicketCreated;
use App\Events\OrderCommitted;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
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
        'payment_method' => 'cash', 'cash_received' => '500.00',
        'items' => [['product_id' => $productId, 'quantity' => 1, 'modifiers' => [], 'notes' => 'Retain this note']]];
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
$schema = $worker ? ($argv[2] ?? '') : 'phase6_'.bin2hex(random_bytes(8));
verify(preg_match('/\Aphase6_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase6_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'broadcasting.default' => 'null', 'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase6_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host, inet_server_port() AS port');
verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'Server must report a loopback address.');

if ($worker) {
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Worker schema isolation failed.');
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '20s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.$argv[3].'_'.$argv[8]]);
    $payload = [...draftPayload($argv[5]), 'idempotency_key' => $argv[6], 'payment_method' => $argv[7]];
    if ($argv[7] === 'split') {
        $payload['cashless_amount'] = '200.00';
    }
    try {
        $order = app(PayNowOrder::class)->execute(User::findOrFail($argv[3]), Branch::findOrFail($argv[4]), $payload);
        $result = ['status' => 'paid', 'order' => $order->id, 'number' => $order->order_number, 'reference' => $order->reference_number];
    } catch (HttpException $exception) {
        $result = ['status' => 'conflict', 'code' => $exception->getStatusCode()];
    } catch (ValidationException $exception) {
        $result = ['status' => 'rejected', 'errors' => $exception->errors()];
    }
    verifyUsableConnection();
    echo json_encode([...$result, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid, 'transaction_level' => DB::transactionLevel()], JSON_THROW_ON_ERROR).PHP_EOL;
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
    $columns = collect(DB::select('SELECT table_name, column_name, data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = ?', [$schema]));
    foreach (['payments', 'kitchen_tickets'] as $table) {
        verify($columns->first(fn ($column): bool => $column->table_name === $table && $column->column_name === 'id')->data_type === 'uuid', 'UUID required.');
    }
    foreach (['amount', 'amount_received', 'change_amount'] as $name) {
        $column = $columns->first(fn ($column): bool => $column->table_name === 'payments' && $column->column_name === $name);
        verify($column->data_type === 'numeric' && $column->numeric_precision === 14 && $column->numeric_scale === 2, 'Exact money required.');
    }
    verify($columns->contains(fn ($column): bool => $column->table_name === 'orders' && $column->column_name === 'reference_number'), 'Missing orders.reference_number.');
    verify($columns->contains(fn ($column): bool => $column->table_name === 'order_number_counters' && $column->column_name === 'next_number' && $column->data_type === 'bigint'), 'Missing bigint order counter.');
    $constraints = collect(DB::select('SELECT c.conname, c.contype, c.confdeltype, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = ?', [$schema]))->keyBy('conname');
    foreach (['payments_amount_check', 'payments_amount_received_check', 'payments_change_amount_check', 'payments_method_check', 'kitchen_tickets_status_check'] as $name) {
        verify(isset($constraints[$name]) && $constraints[$name]->contype === 'c', 'Missing CHECK '.$name);
    }
    foreach (['payments_idempotency_key_unique', 'kitchen_tickets_order_id_unique', 'orders_reference_number_unique'] as $name) {
        verify($constraints[$name]->contype === 'u', 'Missing UNIQUE '.$name);
    }
    verify($constraints['order_number_counters_next_number_check']->contype === 'c', 'Missing positive counter CHECK.');
    verify($constraints['order_number_counters_branch_id_foreign']->confdeltype === 'r', 'Counter branch FK must restrict deletion.');
    foreach (['payments_branch_id_foreign', 'payments_order_id_foreign', 'payments_store_session_id_foreign', 'payments_created_by_user_id_foreign', 'kitchen_tickets_branch_id_foreign', 'kitchen_tickets_order_id_foreign'] as $name) {
        verify($constraints[$name]->confdeltype === 'r', 'Historical FK must restrict deletion: '.$name);
    }
    $indexes = array_column(DB::select('SELECT indexname FROM pg_indexes WHERE schemaname = ?', [$schema]), 'indexname');
    foreach (['payments_order_id_index', 'payments_branch_id_paid_at_index', 'payments_store_session_id_index', 'kitchen_tickets_branch_id_status_index'] as $name) {
        verify(in_array($name, $indexes, true), 'Missing index '.$name);
    }
    echo 'SCHEMA PASS: numeric order identity/counter, UUIDs, numeric(14,2), checks, unique keys, restrictive FKs, indexes.'.PHP_EOL;

    $events = [];
    foreach ([OrderCommitted::class, KitchenTicketCreated::class] as $event) {
        Event::listen($event, function ($event) use (&$events): void {
            verify(DB::transactionLevel() === 0, 'Event dispatched before commit.');
            $events[] = $event->broadcastAs();
        });
    }
    foreach (['cash duplicate', 'last unit', 'split duplicate'] as $scenario) {
        $branch = Branch::factory()->create();
        StoreSession::factory()->for($branch)->create();
        $product = Product::factory()->create(['default_price' => '500.00']);
        BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
        $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $scenario === 'last unit' ? 1 : 10, 'version' => 4]);
        $cashier = User::factory()->create();
        $cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
        $cashier->branches()->attach($branch, ['is_active' => true]);
        $root = (string) Str::uuid();
        $processes = [];
        DB::beginTransaction();
        Branch::query()->whereKey($branch->id)->lockForUpdate()->sole();
        for ($index = 0; $index < 2; $index++) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema, (string) $cashier->id, $branch->id, $product->id,
                $scenario === 'last unit' ? (string) Str::uuid() : $root, $scenario === 'split duplicate' ? 'split' : 'cash', (string) $index], dirname(__DIR__), [
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
            $waiting = $observer->select("SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0", [$schema.'_%']);
            if (count($waiting) === 2) {
                break;
            }
            foreach ($processes as $process) {
                verify($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
            }
            verify(hrtime(true) < $deadline, 'Workers did not overlap at the branch lock.');
            usleep(10_000);
        } while (true);
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        verify(count(array_unique(array_column($results, 'pid'))) === 2, 'Workers must use independent connections.');
        $successes = array_values(array_filter($results, fn (array $result): bool => $result['status'] === 'paid'));
        verify(count($successes) === ($scenario === 'last unit' ? 1 : 2), 'Wrong outcome count.');
        verify(count(array_unique(array_column($successes, 'order'))) === 1, 'Duplicate orders committed.');
        verify(count(array_unique(array_column($successes, 'number'))) === 1 && ctype_digit($successes[0]['number']), 'Operational number must be stable and numeric.');
        verify(count(array_unique(array_column($successes, 'reference'))) === 1 && preg_match('/\A'.preg_quote($branch->code, '/').'-\d{6}-'.$successes[0]['number'].'\z/', $successes[0]['reference']) === 1, 'Full reference must be stable and correctly formatted.');
        if ($scenario === 'last unit') {
            verify(str_contains(json_encode($results), 'Insufficient stock'), 'Loser must report insufficient stock.');
        }
        verify(Order::where('branch_id', $branch->id)->count() === 1, 'Losing request left an orphan draft.');
        verify(Payment::where('branch_id', $branch->id)->count() === ($scenario === 'split duplicate' ? 2 : 1), 'Wrong payment count.');
        verify(KitchenTicket::where('branch_id', $branch->id)->count() === 1, 'Wrong kitchen count.');
        verify(InventoryMovement::where('branch_id', $branch->id)->count() === 1, 'Wrong movement count.');
        verify($balance->fresh()->on_hand === ($scenario === 'last unit' ? 0 : 9) && $balance->fresh()->version === 5, 'Wrong stock/version.');
        verifyUsableConnection();
        echo 'CONCURRENCY PASS: '.$scenario.'; two observed overlapping PostgreSQL workers; one order, stock mutation and ticket; clean reusable connections.'.PHP_EOL;
    }

    $otherBranch = Branch::factory()->create();
    StoreSession::factory()->for($otherBranch)->create();
    $cashier->branches()->attach($otherBranch, ['is_active' => true]);
    $root = (string) Str::uuid();
    $beforeOrders = Order::count();
    $beforePayments = Payment::count();
    $processes = [];
    DB::beginTransaction();
    DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$root]);
    foreach ([[$branch->id, 'cash'], [$otherBranch->id, 'cashless']] as $index => [$branchId, $method]) {
        $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema, (string) $cashier->id, $branchId, $product->id, $root, $method, (string) $index], dirname(__DIR__), [
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
        $waiting = $observer->select("SELECT pid FROM pg_stat_activity WHERE application_name LIKE ? AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0", [$schema.'_%']);
        if (count($waiting) === 2) {
            break;
        }
        verify(hrtime(true) < $deadline, 'Cross-branch requests did not overlap.');
        usleep(10_000);
    } while (true);
    DB::commit();
    $results = [];
    foreach ($processes as $process) {
        verify($process->wait() === 0, 'Cross-branch worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    $statuses = array_column($results, 'status');
    sort($statuses);
    verify($statuses === ['conflict', 'paid'], 'Root key was reused across branches/methods.');
    verify(Order::count() === $beforeOrders + 1 && Payment::count() === $beforePayments + 1, 'Root conflict left duplicate effects.');
    $crossBranchSuccess = collect($results)->firstWhere('status', 'paid');
    verify(ctype_digit($crossBranchSuccess['number']) && is_string($crossBranchSuccess['reference']), 'Cross-branch winner must retain numeric identity and reference.');
    echo 'ROOT SCOPE PASS: concurrent cross-branch Cash/Cashless reuse has one winner and one 409 conflict.'.PHP_EOL;

    $payload = [...draftPayload($product->id), 'idempotency_key' => (string) Str::uuid()];
    $before = [Order::count(), Payment::count(), InventoryMovement::count(), KitchenTicket::count(), $balance->fresh()->on_hand];
    DB::statement("ALTER TABLE kitchen_tickets ADD CONSTRAINT test_kitchen_failure CHECK (status <> 'kitchen') NOT VALID");
    try {
        app(PayNowOrder::class)->execute($cashier, $branch, $payload);
        throw new RuntimeException('Kitchen failure was swallowed.');
    } catch (QueryException $exception) {
        verify($exception->errorInfo[0] === '23514', 'Expected kitchen CHECK failure.');
    } finally {
        DB::statement('ALTER TABLE kitchen_tickets DROP CONSTRAINT test_kitchen_failure');
    }
    verify($before === [Order::count(), Payment::count(), InventoryMovement::count(), KitchenTicket::count(), $balance->fresh()->on_hand], 'Kitchen failure left partial effects.');
    verifyUsableConnection();
    verify($events === [], 'Rolled-back payment dispatched success events.');
    $paid = app(PayNowOrder::class)->execute($cashier, $branch, $payload);
    verify($events === ['order.committed', 'kitchen.ticket_created'], 'Missing after-commit events.');
    app(PayNowOrder::class)->execute($cashier, $branch, $payload);
    verify(count($events) === 2, 'Replay duplicated success events.');
    echo 'EVENT PASS: after commit only, rollback and replay emit no duplicate event.'.PHP_EOL;
    echo 'ROLLBACK PASS: real PostgreSQL kitchen CHECK failure removes payment, draft, inventory mutation and ticket.'.PHP_EOL;
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
