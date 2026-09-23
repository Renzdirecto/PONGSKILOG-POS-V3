<?php

/**
 * Opt-in: DB_URL=null php tests/verify-transaction-history-postgres.php
 * Uses only loopback PostgreSQL and a random phase12_* schema, then removes it.
 */

use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\KitchenStatus;
use App\Models\AuditLog;
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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function phase12Verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{Branch, User, StoreSession} */
function phase12Staff(): array
{
    $branch = Branch::factory()->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier_kitchen')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $session = StoreSession::factory()->for($branch)->create(['opened_by_user_id' => $user->id]);

    return [$branch, $user, $session];
}

/** @return array{Product, BranchInventory} */
function phase12Product(Branch $branch, int $stock = 20, string $price = '100.00'): array
{
    $product = Product::factory()->create(['default_price' => $price]);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $stock]);

    return [$product, $balance];
}

/** @param list<array<string, mixed>> $items */
function phase12Order(User $user, Branch $branch, array $items): Order
{
    $draft = app(CreatePosDraftOrder::class)->execute($user, $branch, ['order_type' => 'take_out', 'items' => $items]);

    return app(CommitPayLaterOrder::class)->execute($user, $branch, $draft, ['idempotency_key' => (string) Str::uuid()]);
}

/** @param list<array<string, mixed>> $items
 * @return array<string, mixed>
 */
function phase12Edit(Order $order, array $items, ?string $key = null): array
{
    return [
        'idempotency_key' => $key ?? (string) Str::uuid(), 'expected_version' => $order->version,
        'order_type' => $order->order_type->value, 'customer_label' => $order->customer_label,
        'branch_table_id' => $order->branch_table_id, 'items' => $items,
    ];
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
phase12Verify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
phase12Verify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
phase12Verify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
phase12Verify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$workerMode = $argv[1] ?? null;
$worker = in_array($workerMode, ['--edit', '--settle', '--kitchen'], true);
$schema = $worker ? ($argv[2] ?? '') : 'phase12_'.bin2hex(random_bytes(8));
phase12Verify(preg_match('/\Aphase12_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase12_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync',
    'broadcasting.default' => 'null', 'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase12_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
phase12Verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.$workerMode.'_'.($argv[7] ?? '0')]);
    $user = User::query()->findOrFail($argv[3]);
    $branch = Branch::query()->findOrFail($argv[4]);
    $order = Order::query()->findOrFail($argv[5]);
    $payload = json_decode(base64_decode($argv[6], true), true, flags: JSON_THROW_ON_ERROR);
    try {
        $result = match ($workerMode) {
            '--edit' => app(EditCommittedOrder::class)->execute($user, $branch, $order, $payload),
            '--settle' => app(SettlePayLaterOrder::class)->execute($user, $branch, $order, $payload),
            '--kitchen' => app(TransitionKitchenOrder::class)->execute($user, $branch, $order, KitchenStatus::from($payload['status'])),
        };
        $output = ['status' => 'success', 'version' => $result->version];
    } catch (HttpException $exception) {
        $output = ['status' => 'conflict', 'code' => $exception->getStatusCode()];
    } catch (ValidationException $exception) {
        $output = ['status' => 'rejected', 'errors' => $exception->errors()];
    }
    echo json_encode([...$output, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/** @param list<array{mode: string, order: Order, payload: array<string, mixed>}> $jobs
 * @return list<array<string, mixed>>
 */
function phase12Race(string $schema, array $connection, object $observer, User $user, Branch $branch, StoreSession $session, array $jobs, bool $verifyBlocked = false): array
{
    $environment = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
    ];
    DB::beginTransaction();
    StoreSession::query()->whereKey($session->id)->lockForUpdate()->sole();
    $processes = [];
    foreach ($jobs as $index => $job) {
        $process = new Process([
            PHP_BINARY, __FILE__, $job['mode'], $schema, (string) $user->id, $branch->id,
            $job['order']->id, base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), (string) $index,
        ], dirname(__DIR__), $environment, timeout: 55);
        $process->start();
        $processes[] = $process;
    }
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'%'],
        );
        if (count($waiting) === count($jobs)) {
            break;
        }
        phase12Verify(hrtime(true) < $deadline, 'Workers did not overlap at the Store Session boundary.');
        usleep(10_000);
    } while (true);
    if ($verifyBlocked) {
        echo 'CASE I PASS: edit waits behind the future Store-close exclusive Session boundary.'.PHP_EOL;
    }
    DB::commit();
    $results = [];
    foreach ($processes as $process) {
        phase12Verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    phase12Verify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers did not use independent connections.');

    return $results;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    phase12Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migration failed.');
    phase12Verify(Artisan::call('migrate:rollback', ['--step' => count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_22_125245')), '--force' => true, '--no-interaction' => true]) === 0, 'Phase 12 rollback failed.');
    phase12Verify(! DB::getSchemaBuilder()->hasTable('payment_invoice_proofs'), 'Phase 12 rollback left proof schema behind.');
    phase12Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 12 reapply failed.');
    (new RbacSeeder)->run();
    Event::fake();
    echo 'FRESH MIGRATION PASS '.$schema.PHP_EOL;

    // A: concurrent edit, same expected version.
    [$branch, $user, $session] = phase12Staff();
    [$product] = phase12Product($branch);
    $order = phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $item = $order->items()->sole();
    $base = [['existing_order_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]];
    $results = phase12Race($schema, $connection, $observer, $user, $branch, $session, [
        ['mode' => '--edit', 'order' => $order, 'payload' => phase12Edit($order, $base)],
        ['mode' => '--edit', 'order' => $order, 'payload' => phase12Edit($order, $base)],
    ]);
    phase12Verify(collect($results)->where('status', 'success')->count() === 1 && collect($results)->where('status', 'conflict')->count() === 1, 'A: stale writer was not rejected.');
    echo 'CASE A PASS: one edit wins and one stale writer receives conflict.'.PHP_EOL;

    // B: two Orders compete for the last stock unit.
    [$branch, $user, $session] = phase12Staff();
    [$product, $balance] = phase12Product($branch, 3);
    $orders = [phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]), phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]])];
    $jobs = [];
    foreach ($orders as $candidate) {
        $candidateItem = $candidate->items()->sole();
        $jobs[] = ['mode' => '--edit', 'order' => $candidate, 'payload' => phase12Edit($candidate, [['existing_order_item_id' => $candidateItem->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]])];
    }
    $results = phase12Race($schema, $connection, $observer, $user, $branch, $session, $jobs);
    phase12Verify(collect($results)->where('status', 'success')->count() === 1 && $balance->fresh()->on_hand === 0, 'B: last stock race was not bounded.');
    echo 'CASE B PASS: one stock edit wins, one rejects, inventory remains zero.'.PHP_EOL;

    // C: edit versus initial Pay Later settlement.
    [$branch, $user, $session] = phase12Staff();
    [$product] = phase12Product($branch);
    $order = phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $item = $order->items()->sole();
    phase12Race($schema, $connection, $observer, $user, $branch, $session, [
        ['mode' => '--edit', 'order' => $order, 'payload' => phase12Edit($order, [['existing_order_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]])],
        ['mode' => '--settle', 'order' => $order, 'payload' => ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash', 'cash_received' => '200.00', 'cashless_amount' => null]],
    ]);
    $order->refresh()->load('payments', 'adjustments');
    phase12Verify((float) $order->payments->sum('amount') <= (float) $order->total && in_array($order->payment_status->value, ['partial', 'paid'], true), 'C: edit/settlement final state is inconsistent.');
    echo 'CASE C PASS: edit versus Pay Later settlement remains internally consistent.'.PHP_EOL;

    // D/E: higher-total edit then duplicate delta settlement.
    [$branch, $user, $session] = phase12Staff();
    [$product] = phase12Product($branch);
    $order = phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    app(SettlePayLaterOrder::class)->execute($user, $branch, $order, ['idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cashless', 'cash_received' => null, 'cashless_amount' => null]);
    $item = $order->items()->sole();
    app(EditCommittedOrder::class)->execute($user, $branch, $order->fresh(), phase12Edit($order->fresh(), [['existing_order_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]]));
    $deltaKey = (string) Str::uuid();
    $delta = ['idempotency_key' => $deltaKey, 'payment_method' => 'cashless', 'cash_received' => null, 'cashless_amount' => null];
    $results = phase12Race($schema, $connection, $observer, $user, $branch, $session, [
        ['mode' => '--settle', 'order' => $order, 'payload' => $delta], ['mode' => '--settle', 'order' => $order, 'payload' => $delta],
    ]);
    phase12Verify(collect($results)->where('status', 'success')->count() === 2 && Payment::where('order_id', $order->id)->count() === 2 && (float) Payment::where('order_id', $order->id)->sum('amount') === 200.0, 'D/E: duplicate delta settlement created wrong money.');
    echo 'CASE D PASS: higher total and balance settlement do not overpay.'.PHP_EOL;
    echo 'CASE E PASS: duplicate delta payment replays with one appended Payment.'.PHP_EOL;

    // F: same edit request replay.
    [$branch, $user] = phase12Staff();
    [$product] = phase12Product($branch);
    $order = phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $item = $order->items()->sole();
    $payload = phase12Edit($order, [['existing_order_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]]);
    app(EditCommittedOrder::class)->execute($user, $branch, $order, $payload);
    app(EditCommittedOrder::class)->execute($user, $branch, $order, $payload);
    phase12Verify(AuditLog::where('auditable_id', $order->id)->where('action', 'committed_order_edited')->count() === 1 && InventoryMovement::where('order_id', $order->id)->where('movement_type', 'order_edit_delta')->count() === 1, 'F: replay duplicated effects.');
    echo 'CASE F PASS: exact edit replay has no duplicate audit or inventory effect.'.PHP_EOL;

    // G: reverse Product request order across two Orders.
    [$branch, $user, $session] = phase12Staff();
    [$first] = phase12Product($branch);
    [$second] = phase12Product($branch);
    $orders = [phase12Order($user, $branch, [['product_id' => $first->id, 'quantity' => 1, 'modifiers' => []], ['product_id' => $second->id, 'quantity' => 1, 'modifiers' => []]]), phase12Order($user, $branch, [['product_id' => $second->id, 'quantity' => 1, 'modifiers' => []], ['product_id' => $first->id, 'quantity' => 1, 'modifiers' => []]])];
    $jobs = [];
    foreach ($orders as $candidate) {
        $lines = $candidate->items()->get()->reverse()->map(fn ($line): array => ['existing_order_item_id' => $line->id, 'product_id' => $line->product_id, 'quantity' => 2, 'notes' => null, 'modifiers' => []])->values()->all();
        $jobs[] = ['mode' => '--edit', 'order' => $candidate, 'payload' => phase12Edit($candidate, $lines)];
    }
    $results = phase12Race($schema, $connection, $observer, $user, $branch, $session, $jobs);
    phase12Verify(collect($results)->where('status', 'success')->count() === 2, 'G: reverse Product order deadlocked.');
    echo 'CASE G PASS: sorted Product locks avoid reverse-order deadlock.'.PHP_EOL;

    // H/I: edit versus Kitchen transition, with explicit Session blocking observation.
    [$branch, $user, $session] = phase12Staff();
    [$product] = phase12Product($branch);
    $order = phase12Order($user, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $item = $order->items()->sole();
    $results = phase12Race($schema, $connection, $observer, $user, $branch, $session, [
        ['mode' => '--edit', 'order' => $order, 'payload' => phase12Edit($order, [['existing_order_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]])],
        ['mode' => '--kitchen', 'order' => $order, 'payload' => ['status' => 'preparing']],
    ], true);
    $order->refresh();
    phase12Verify(KitchenTicket::where('order_id', $order->id)->count() === 1 && in_array($order->kitchen_status->value, ['kitchen', 'preparing'], true) && collect($results)->where('status', 'success')->count() >= 1, 'H: edit/Kitchen race broke lifecycle.');
    echo 'CASE H PASS: content edit and Kitchen lifecycle serialize without duplicate ticket.'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    }
}

echo 'PHASE 12 POSTGRESQL HARNESS PASS'.PHP_EOL;
