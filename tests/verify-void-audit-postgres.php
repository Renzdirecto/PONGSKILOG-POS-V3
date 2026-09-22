<?php

/**
 * Opt-in: DB_URL=null php tests/verify-void-audit-postgres.php
 * Uses only loopback PostgreSQL and a random phase13_* schema, then removes it.
 */

use App\Actions\Audit\AuditRecorder;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Actions\Orders\TransitionKitchenOrder;
use App\Actions\Orders\VoidOrder;
use App\Enums\KitchenStatus;
use App\Http\Controllers\SetVoidAuthorizationPinController;
use App\Http\Requests\SetVoidAuthorizationPinRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderVoid;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Models\VoidAuthorizationSetting;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function phase13Verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{Branch, User, User, StoreSession} */
function phase13Staff(): array
{
    $branch = Branch::factory()->create();
    $cashier = User::factory()->create();
    $cashier->roles()->attach(Role::query()->where('name', 'cashier_kitchen')->sole());
    $cashier->branches()->attach($branch, ['is_active' => true]);
    $superAdmin = User::factory()->create();
    $superAdmin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    VoidAuthorizationSetting::query()->updateOrCreate(['scope' => 'global'], [
        'pin_hash' => Hash::make('2468'),
        'configured_by_user_id' => $superAdmin->id,
        'configured_at' => now(),
    ]);
    $session = StoreSession::factory()->for($branch)->create(['opened_by_user_id' => $cashier->id]);

    return [$branch, $cashier, $superAdmin, $session];
}

/** @return array{Product, BranchInventory} */
function phase13Product(Branch $branch, int $stock = 20): array
{
    $product = Product::factory()->create(['default_price' => '100.00']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => $stock]);

    return [$product, $balance];
}

/** @param list<array{product_id: string, quantity: int, modifiers: list<mixed>}> $items */
function phase13Order(User $cashier, Branch $branch, array $items): Order
{
    $draft = app(CreatePosDraftOrder::class)->execute($cashier, $branch, [
        'order_type' => 'take_out',
        'items' => $items,
    ]);

    return app(CommitPayLaterOrder::class)->execute($cashier, $branch, $draft, [
        'idempotency_key' => (string) Str::uuid(),
    ]);
}

/** @return array<string, mixed> */
function phase13VoidPayload(Order $order, ?string $key = null): array
{
    return [
        'reason_code' => 'wrong_item',
        'reason_text' => null,
        'authorization_pin' => '2468',
        'idempotency_key' => $key ?? (string) Str::uuid(),
        'expected_version' => $order->version,
    ];
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
phase13Verify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
phase13Verify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
phase13Verify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
phase13Verify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$workerMode = $argv[1] ?? null;
$worker = in_array($workerMode, ['--void', '--edit', '--settle', '--kitchen', '--pin'], true);
$schema = $worker ? ($argv[2] ?? '') : 'phase13_'.bin2hex(random_bytes(8));
phase13Verify(preg_match('/\Aphase13_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase13_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase13_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
phase13Verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.$workerMode.'_'.($argv[7] ?? '0')]);
    $user = User::query()->findOrFail($argv[3]);
    $branch = $workerMode === '--pin' ? null : Branch::query()->findOrFail($argv[4]);
    $order = $workerMode === '--pin' ? null : Order::query()->findOrFail($argv[5]);
    $payload = json_decode(base64_decode($argv[6], true), true, flags: JSON_THROW_ON_ERROR);

    try {
        if ($workerMode === '--pin') {
            $request = SetVoidAuthorizationPinRequest::create('/workspaces/void-orders/pin', 'PUT', [
                'pin' => $payload['pin'],
                'pin_confirmation' => $payload['pin'],
            ]);
            $request->setUserResolver(fn (): User => $user);
            app(SetVoidAuthorizationPinController::class)($request, app(AuditRecorder::class));
            $result = VoidAuthorizationSetting::query()->sole();
        } else {
            $result = match ($workerMode) {
                '--void' => app(VoidOrder::class)->execute($user, $branch, $order, $payload),
                '--edit' => app(EditCommittedOrder::class)->execute($user, $branch, $order, $payload),
                '--settle' => app(SettlePayLaterOrder::class)->execute($user, $branch, $order, $payload),
                '--kitchen' => app(TransitionKitchenOrder::class)->execute($user, $branch, $order, KitchenStatus::from($payload['status'])),
            };
        }
        $output = ['status' => 'success', 'id' => (string) $result->getKey()];
    } catch (HttpException $exception) {
        $output = ['status' => 'conflict', 'code' => $exception->getStatusCode()];
    } catch (ValidationException $exception) {
        $output = ['status' => 'rejected', 'errors' => $exception->errors()];
    }

    echo json_encode([...$output, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/** @return array<string, string> */
function phase13Environment(array $connection): array
{
    return [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
    ];
}

/**
 * @param  list<array{mode: string, user: User, branch: Branch, order: Order, payload: array<string, mixed>}>  $jobs
 * @return list<array<string, mixed>>
 */
function phase13Race(string $schema, array $connection, object $observer, StoreSession $session, array $jobs, bool $holdSession = true): array
{
    if ($holdSession) {
        DB::beginTransaction();
        StoreSession::query()->whereKey($session->id)->lockForUpdate()->sole();
    }

    $processes = [];
    foreach ($jobs as $index => $job) {
        $process = new Process([
            PHP_BINARY, __FILE__, $job['mode'], $schema, (string) $job['user']->id,
            $job['branch']->id, $job['order']->id,
            base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), (string) $index,
        ], dirname(__DIR__), phase13Environment($connection), timeout: 55);
        $process->start();
        $processes[] = $process;
    }

    if ($holdSession) {
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $waiting = $observer->select(
                "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
                [$schema.'%'],
            );
            if (count($waiting) === count($jobs)) {
                break;
            }
            phase13Verify(hrtime(true) < $deadline, 'Workers did not overlap at the Store Session boundary.');
            usleep(10_000);
        } while (true);
        DB::commit();
    }

    $results = [];
    foreach ($processes as $process) {
        phase13Verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    phase13Verify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers did not use independent connections.');

    return $results;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    phase13Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migration failed.');
    phase13Verify(Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true, '--no-interaction' => true]) === 0, 'Phase 13 rollback failed.');
    phase13Verify(! DB::getSchemaBuilder()->hasTable('order_voids') && ! DB::getSchemaBuilder()->hasTable('void_authorization_settings'), 'Phase 13 rollback left schema behind.');
    phase13Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 13 reapply failed.');
    (new RbacSeeder)->run();
    echo 'FRESH/ROLLBACK/REAPPLY PASS '.$schema.PHP_EOL;

    // A: duplicate same-key Void.
    [$branch, $cashier, , $session] = phase13Staff();
    [$product, $balance] = phase13Product($branch);
    $order = phase13Order($cashier, $branch, [['product_id' => $product->id, 'quantity' => 2, 'modifiers' => []]]);
    $payload = phase13VoidPayload($order);
    $results = phase13Race($schema, $connection, $observer, $session, [
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => $payload],
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => $payload],
    ], holdSession: false);
    phase13Verify(collect($results)->where('status', 'success')->count() === 2
        && OrderVoid::where('order_id', $order->id)->count() === 1
        && InventoryMovement::where('order_id', $order->id)->where('movement_type', 'void_restore')->count() === 1
        && AuditLog::where('auditable_id', $order->id)->where('action', 'order.voided')->count() === 1
        && $balance->fresh()->on_hand === 20, 'A: duplicate replay created an invalid effect.');
    echo 'CASE A PASS: duplicate same-key Void produced one Void, restoration, and audit.'.PHP_EOL;

    // B: competing distinct-key Void.
    [$branch, $cashier, , $session] = phase13Staff();
    [$product] = phase13Product($branch);
    $order = phase13Order($cashier, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $results = phase13Race($schema, $connection, $observer, $session, [
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => phase13VoidPayload($order)],
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => phase13VoidPayload($order)],
    ]);
    phase13Verify(collect($results)->where('status', 'success')->count() === 1 && OrderVoid::where('order_id', $order->id)->count() === 1, 'B: distinct-key Void did not have one winner.');
    echo 'CASE B PASS: competing distinct-key Void had one winner.'.PHP_EOL;

    // C: Void versus committed edit.
    [$branch, $cashier, , $session] = phase13Staff();
    [$product] = phase13Product($branch);
    $order = phase13Order($cashier, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $item = $order->items()->sole();
    $results = phase13Race($schema, $connection, $observer, $session, [
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => phase13VoidPayload($order)],
        ['mode' => '--edit', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => [
            'idempotency_key' => (string) Str::uuid(), 'expected_version' => $order->version,
            'order_type' => 'take_out', 'customer_label' => null, 'branch_table_id' => null,
            'items' => [['existing_order_item_id' => $item->id, 'product_id' => $product->id, 'quantity' => 2, 'notes' => null, 'modifiers' => []]],
        ]],
    ]);
    $order->refresh();
    phase13Verify(collect($results)->where('status', 'success')->count() === 1
        && in_array($order->commercial_status->value, ['active', 'voided'], true), 'C: Void/edit race did not serialize.');
    echo 'CASE C PASS: Void versus committed edit serialized to one valid winner.'.PHP_EOL;

    // D: Void versus Pay Later/balance settlement.
    [$branch, $cashier, , $session] = phase13Staff();
    [$product] = phase13Product($branch);
    $order = phase13Order($cashier, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $results = phase13Race($schema, $connection, $observer, $session, [
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => phase13VoidPayload($order)],
        ['mode' => '--settle', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => [
            'idempotency_key' => (string) Str::uuid(), 'payment_method' => 'cash',
            'cash_received' => '100.00', 'cashless_amount' => null,
        ]],
    ]);
    $order->refresh();
    phase13Verify(collect($results)->where('status', 'success')->count() === 1
        && ! ($order->commercial_status->value === 'voided' && Payment::where('order_id', $order->id)->exists()), 'D: Void/settlement produced an invalid combination.');
    echo 'CASE D PASS: Void versus settlement retained a valid payment/order combination.'.PHP_EOL;

    // E: Void versus Kitchen transition.
    [$branch, $cashier, , $session] = phase13Staff();
    [$product] = phase13Product($branch);
    $order = phase13Order($cashier, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $results = phase13Race($schema, $connection, $observer, $session, [
        ['mode' => '--void', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => phase13VoidPayload($order)],
        ['mode' => '--kitchen', 'user' => $cashier, 'branch' => $branch, 'order' => $order, 'payload' => ['status' => 'preparing']],
    ]);
    phase13Verify(collect($results)->where('status', 'success')->count() === 1
        && KitchenTicket::where('order_id', $order->id)->count() === 1, 'E: Void/Kitchen race broke ticket history.');
    echo 'CASE E PASS: Void versus Kitchen transition retained one valid ticket.'.PHP_EOL;

    // F: reverse Product source order across two Voids.
    [$branch, $cashier, , $session] = phase13Staff();
    [$first] = phase13Product($branch, 30);
    [$second] = phase13Product($branch, 30);
    $orders = [
        phase13Order($cashier, $branch, [['product_id' => $first->id, 'quantity' => 1, 'modifiers' => []], ['product_id' => $second->id, 'quantity' => 1, 'modifiers' => []]]),
        phase13Order($cashier, $branch, [['product_id' => $second->id, 'quantity' => 1, 'modifiers' => []], ['product_id' => $first->id, 'quantity' => 1, 'modifiers' => []]]),
    ];
    $results = phase13Race($schema, $connection, $observer, $session, array_map(fn (Order $candidate): array => [
        'mode' => '--void', 'user' => $cashier, 'branch' => $branch,
        'order' => $candidate, 'payload' => phase13VoidPayload($candidate),
    ], $orders));
    phase13Verify(collect($results)->where('status', 'success')->count() === 2, 'F: reverse Product restoration order deadlocked.');
    echo 'CASE F PASS: sorted Product restoration avoided reverse-order deadlock.'.PHP_EOL;

    // G: explicit future Store Close boundary observation.
    [$branch, $cashier, , $session] = phase13Staff();
    [$product] = phase13Product($branch);
    $order = phase13Order($cashier, $branch, [['product_id' => $product->id, 'quantity' => 1, 'modifiers' => []]]);
    $results = phase13Race($schema, $connection, $observer, $session, [[
        'mode' => '--void', 'user' => $cashier, 'branch' => $branch,
        'order' => $order, 'payload' => phase13VoidPayload($order),
    ]]);
    phase13Verify($results[0]['status'] === 'success', 'G: Void failed after the Store Session boundary released.');
    echo 'CASE G PASS: Void waited behind the future Store-close exclusive Session boundary.'.PHP_EOL;

    // Global PIN changes serialize even when the singleton row does not exist yet.
    VoidAuthorizationSetting::query()->delete();
    $admins = User::factory()->count(2)->create();
    foreach ($admins as $admin) {
        $admin->roles()->attach(Role::query()->where('name', 'super_admin')->sole());
    }
    DB::beginTransaction();
    DB::select("SELECT pg_advisory_xact_lock(hashtextextended('void_authorization_settings:global', 0))");
    $pinProcesses = [];
    foreach ($admins as $index => $admin) {
        $pin = $index === 0 ? '1357' : '8642';
        $process = new Process([
            PHP_BINARY, __FILE__, '--pin', $schema, (string) $admin->id, '-', '-',
            base64_encode(json_encode(['pin' => $pin], JSON_THROW_ON_ERROR)), (string) $index,
        ], dirname(__DIR__), phase13Environment($connection), timeout: 55);
        $process->start();
        $pinProcesses[] = $process;
    }
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'--pin%'],
        );
        if (count($waiting) === 2) {
            break;
        }
        phase13Verify(hrtime(true) < $deadline, 'Concurrent PIN changes did not overlap.');
        usleep(10_000);
    } while (true);
    DB::commit();
    foreach ($pinProcesses as $process) {
        phase13Verify($process->wait() === 0, 'PIN worker failed: '.$process->getErrorOutput().$process->getOutput());
        phase13Verify(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'] === 'success', 'PIN worker was rejected.');
    }
    $setting = VoidAuthorizationSetting::query()->sole();
    phase13Verify(Hash::check('1357', $setting->pin_hash) || Hash::check('8642', $setting->pin_hash), 'Final PIN is not one submitted value.');
    phase13Verify(AuditLog::where('action', 'void_pin.configured')->count() === 2, 'Concurrent PIN changes did not each produce an audit.');
    echo 'PIN CONCURRENCY PASS: one global row remains and the latest serialized PIN is authoritative.'.PHP_EOL;
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    }
}

echo 'PHASE 13 POSTGRESQL HARNESS PASS'.PHP_EOL;
