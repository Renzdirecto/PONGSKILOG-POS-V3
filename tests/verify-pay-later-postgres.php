<?php

/**
 * Opt-in: DB_URL=null php tests/verify-pay-later-postgres.php with local DB_* settings.
 * Only a random phase7_* schema is migrated and removed; public is never written.
 */

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\SettlePayLaterOrder;
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

function phase7Verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase7UsableConnection(): void
{
    phase7Verify(DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction(), 'Transaction did not close.');
    phase7Verify(DB::selectOne('SELECT 1 AS usable')->usable === 1, 'Connection is unusable.');
}

/** @return array<string, mixed> */
function phase7CommitPayload(string $productId, int $quantity = 1): array
{
    return [
        'order_type' => 'take_out',
        'customer_label' => 'Phase 7 PostgreSQL',
        'items' => [['product_id' => $productId, 'quantity' => $quantity, 'modifiers' => []]],
    ];
}

/** @return array{Branch, User, Product, BranchInventory, StoreSession} */
function phase7Fixture(int $stock = 10, string $price = '500.00'): array
{
    $branch = Branch::factory()->create();
    $session = StoreSession::factory()->for($branch)->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $product = Product::factory()->create(['default_price' => $price]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $stock, 'version' => 1]);

    return [$branch, $user, $product, $balance, $session];
}

/** @param list<array<string, mixed>> $items */
function phase7Draft(User $user, Branch $branch, array $items): Order
{
    return app(CreatePosDraftOrder::class)->execute($user, $branch, [
        'order_type' => 'take_out',
        'items' => $items,
    ]);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
phase7Verify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
phase7Verify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
phase7Verify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
phase7Verify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');
phase7Verify(ctype_digit((string) $connection['port']), 'A single numeric local port is required.');

$workerMode = $argv[1] ?? null;
$worker = in_array($workerMode, ['--commit-worker', '--settle-worker'], true);
$schema = $worker ? ($argv[2] ?? '') : 'phase7_'.bin2hex(random_bytes(8));
phase7Verify(preg_match('/\Aphase7_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase7_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase7_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host, inet_server_port() AS port');
phase7Verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'Server must report a loopback address.');

if ($worker) {
    phase7Verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Worker schema isolation failed.');
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    $user = User::query()->findOrFail($argv[3]);
    $branch = Branch::query()->findOrFail($argv[4]);
    $order = Order::query()->findOrFail($argv[5]);
    $key = $argv[6];
    $tag = $argv[7];
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.$workerMode.'_'.$tag]);

    try {
        if ($workerMode === '--commit-worker') {
            $result = app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => $key]);
        } else {
            $method = $argv[8];
            $payload = ['idempotency_key' => $key, 'payment_method' => $method];
            if ($method === 'cash') {
                $payload['cash_received'] = '500.00';
            } elseif ($method === 'split') {
                $payload['cash_received'] = '300.00';
                $payload['cashless_amount'] = '200.00';
            }
            $result = app(SettlePayLaterOrder::class)->execute($user, $branch, $order, $payload);
        }
        $output = ['status' => 'success', 'order' => $result->id];
    } catch (HttpException $exception) {
        $output = ['status' => 'conflict', 'code' => $exception->getStatusCode()];
    } catch (ValidationException $exception) {
        $output = ['status' => 'rejected', 'errors' => $exception->errors()];
    }
    phase7UsableConnection();
    echo json_encode([...$output, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/** @return list<array<string, mixed>> */
function phase7Overlap(
    string $mode,
    string $schema,
    array $connection,
    object $observer,
    User $user,
    Branch $branch,
    array $orders,
    array $keys,
    string $method = 'cashless',
): array {
    $environment = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => 'null',
        'DB_HOST' => $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_SSLMODE' => $connection['sslmode'],
    ];
    $processes = [];
    DB::beginTransaction();
    Branch::query()->whereKey($branch->id)->lockForUpdate()->sole();
    foreach ([0, 1] as $index) {
        $arguments = [
            PHP_BINARY,
            __FILE__,
            $mode,
            $schema,
            (string) $user->id,
            $branch->id,
            $orders[$index]->id,
            $keys[$index],
            (string) $index,
        ];
        if ($mode === '--settle-worker') {
            $arguments[] = $method;
        }
        $process = new Process($arguments, dirname(__DIR__), $environment, timeout: 55);
        $processes[] = $process;
        $process->start();
    }

    $deadline = hrtime(true) + 25_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'_'.$mode.'_%'],
        );
        if (count($waiting) === 2) {
            break;
        }
        foreach ($processes as $process) {
            phase7Verify($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        phase7Verify(hrtime(true) < $deadline, 'Workers did not overlap at the branch lock.');
        usleep(10_000);
    } while (true);
    DB::commit();

    $results = [];
    foreach ($processes as $process) {
        phase7Verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    phase7Verify(count(array_unique(array_column($results, 'pid'))) === 2, 'Workers did not use independent connections.');

    return $results;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    phase7Verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    phase7Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Isolated fresh migration failed.');
    (new RbacSeeder)->run();
    phase7Verify(DB::selectOne("SELECT data_type FROM information_schema.columns WHERE table_schema = ? AND table_name = 'orders' AND column_name = 'pay_later_idempotency_key'", [$schema])->data_type === 'uuid', 'Pay Later key must be UUID.');
    echo 'FRESH MIGRATION PASS '.$schema.PHP_EOL;

    [$branch, $user, $product, $balance] = phase7Fixture();
    $order = phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']);
    $sameKey = (string) Str::uuid();
    $results = phase7Overlap('--commit-worker', $schema, $connection, $observer, $user, $branch, [$order, $order], [$sameKey, $sameKey]);
    phase7Verify(array_column($results, 'status') === ['success', 'success'], 'Same-key duplicate did not replay successfully.');
    phase7Verify($balance->fresh()->on_hand === 9 && InventoryMovement::where('order_id', $order->id)->count() === 1, 'Same-key duplicate moved stock more than once.');
    phase7Verify(KitchenTicket::where('order_id', $order->id)->count() === 1 && Payment::where('order_id', $order->id)->count() === 0, 'Same-key duplicate produced wrong kitchen/payment effects.');
    echo 'CONCURRENCY PASS: same order + same activation key commits stock/ticket once and replays once.'.PHP_EOL;

    [$branch, $user, $product, $balance] = phase7Fixture();
    $order = phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']);
    $results = phase7Overlap('--commit-worker', $schema, $connection, $observer, $user, $branch, [$order, $order], [(string) Str::uuid(), (string) Str::uuid()]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    phase7Verify($statuses === ['conflict', 'success'], 'Different activation keys did not produce one winner and one conflict.');
    phase7Verify($balance->fresh()->on_hand === 9 && InventoryMovement::where('order_id', $order->id)->count() === 1 && KitchenTicket::where('order_id', $order->id)->count() === 1, 'Different-key race duplicated effects.');
    echo 'CONCURRENCY PASS: same order + different keys has one commit and one already-committed conflict.'.PHP_EOL;

    [$branch, $user, $product, $balance] = phase7Fixture(1);
    $orders = [
        phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']),
        phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']),
    ];
    $results = phase7Overlap('--commit-worker', $schema, $connection, $observer, $user, $branch, $orders, [(string) Str::uuid(), (string) Str::uuid()]);
    $statuses = array_column($results, 'status');
    sort($statuses);
    phase7Verify($statuses === ['rejected', 'success'] && str_contains(json_encode($results), 'Insufficient stock'), 'Last-unit race did not have one stock loser.');
    phase7Verify($balance->fresh()->on_hand === 0 && InventoryMovement::where('branch_id', $branch->id)->count() === 1 && KitchenTicket::where('branch_id', $branch->id)->count() === 1, 'Last-unit loser left partial effects.');
    echo 'CONCURRENCY PASS: last unit has exactly one winner and one insufficient-stock rollback.'.PHP_EOL;

    [$branch, $user, $product, $balance] = phase7Fixture();
    $order = phase7Draft($user, $branch, [
        ['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []],
        ['product_id' => $product->id, 'quantity' => 2, 'modifiers' => []],
    ]);
    app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]);
    phase7Verify($balance->fresh()->on_hand === 7 && InventoryMovement::where('order_id', $order->id)->where('quantity_delta', -3)->count() === 1, 'Repeated lines were not aggregated.');
    echo 'AGGREGATION PASS: repeated product lines produce one sorted product movement with delta -3.'.PHP_EOL;

    [$branch, $user] = phase7Fixture();
    $products = Product::factory()->count(2)->create(['default_price' => '100.00']);
    foreach ($products as $multiProduct) {
        BranchProduct::factory()->for($branch)->for($multiProduct)->create(['tracks_inventory' => true]);
        BranchInventory::factory()->for($branch)->for($multiProduct)->create(['on_hand' => 2]);
    }
    $forward = $products->map(fn (Product $item): array => ['product_id' => $item->id, 'quantity' => 1, 'modifiers' => []])->all();
    $reverse = array_reverse($forward);
    $orders = [phase7Draft($user, $branch, $forward), phase7Draft($user, $branch, $reverse)];
    $results = phase7Overlap('--commit-worker', $schema, $connection, $observer, $user, $branch, $orders, [(string) Str::uuid(), (string) Str::uuid()]);
    phase7Verify(array_column($results, 'status') === ['success', 'success'], 'Reversed-product commits did not both succeed.');
    phase7Verify($products->every(fn (Product $item): bool => BranchInventory::where('branch_id', $branch->id)->where('product_id', $item->id)->value('on_hand') === 0), 'Reversed-product commits lost stock.');
    echo 'CONCURRENCY PASS: reversed product order commits without deadlock or partial inventory.'.PHP_EOL;

    [$branch, $user, $product, $balance] = phase7Fixture();
    $order = phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']);
    DB::statement("ALTER TABLE kitchen_tickets ADD CONSTRAINT phase7_ticket_failure CHECK (status <> 'kitchen') NOT VALID");
    try {
        app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]);
        throw new RuntimeException('Kitchen failure was swallowed.');
    } catch (QueryException $exception) {
        phase7Verify($exception->errorInfo[0] === '23514', 'Expected a PostgreSQL kitchen CHECK failure.');
    } finally {
        DB::statement('ALTER TABLE kitchen_tickets DROP CONSTRAINT phase7_ticket_failure');
    }
    phase7Verify($order->fresh()->commercial_status->value === 'draft' && $balance->fresh()->on_hand === 10, 'Kitchen failure committed order or stock.');
    phase7Verify(InventoryMovement::where('order_id', $order->id)->count() === 0 && KitchenTicket::where('order_id', $order->id)->count() === 0 && Payment::where('order_id', $order->id)->count() === 0, 'Kitchen failure left partial rows.');
    phase7UsableConnection();
    echo 'ROLLBACK PASS: kitchen failure leaves draft, stock, payments and ticket unchanged.'.PHP_EOL;

    foreach (['cash', 'split'] as $method) {
        [$branch, $user, $product, $balance] = phase7Fixture();
        $order = phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']);
        app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid()]);
        $settlementKey = (string) Str::uuid();
        $results = phase7Overlap('--settle-worker', $schema, $connection, $observer, $user, $branch, [$order, $order], [$settlementKey, $settlementKey], $method);
        phase7Verify(array_column($results, 'status') === ['success', 'success'], 'Duplicate settlement did not replay successfully.');
        phase7Verify(Payment::where('order_id', $order->id)->count() === ($method === 'split' ? 2 : 1), 'Duplicate settlement created extra payment legs.');
        phase7Verify($balance->fresh()->on_hand === 9 && InventoryMovement::where('order_id', $order->id)->count() === 1 && KitchenTicket::where('order_id', $order->id)->count() === 1, 'Settlement repeated inventory or kitchen.');
        echo 'SETTLEMENT PASS: concurrent duplicate '.$method.' creates exact payment legs with no stock/kitchen repeat.'.PHP_EOL;
    }

    $events = [];
    foreach ([OrderCommitted::class, KitchenTicketCreated::class] as $eventClass) {
        Event::listen($eventClass, function ($event) use (&$events): void {
            phase7Verify(DB::transactionLevel() === 0, 'Success event dispatched before commit.');
            $events[] = $event->broadcastAs();
        });
    }
    [$branch, $user, $product] = phase7Fixture();
    $order = phase7Draft($user, $branch, phase7CommitPayload($product->id)['items']);
    $key = (string) Str::uuid();
    app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => $key]);
    app(CommitPayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => $key]);
    phase7Verify($events === ['order.committed', 'kitchen.ticket_created'], 'Replay duplicated after-commit events.');
    echo 'EVENT PASS: initial commit emits after commit; replay emits no event.'.PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        phase7Verify($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS '.$schema.PHP_EOL;
    }
}
