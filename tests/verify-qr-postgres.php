<?php

/**
 * Opt-in: DB_URL=null php tests/verify-qr-postgres.php with local PostgreSQL DB_* settings.
 * Uses independent workers and observed PostgreSQL lock waits in a random isolated schema.
 * --sqlite-migrations verifies fresh/up/down/up in a temporary SQLite database instead.
 */

use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Actions\Orders\CancelLoadedCustomerQrOrder;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\LoadCustomerQrOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\RestoreCustomerQrOrder;
use App\Actions\Orders\SubmitCustomerQrOrder;
use App\Enums\CommercialStatus;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\CustomerQrSession;
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
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function qrVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{Branch, User, Product, BranchInventory, StoreSession, CustomerQrSession} */
function qrRaceFixture(): array
{
    $branch = Branch::factory()->create();
    $store = StoreSession::factory()->for($branch)->create();
    $user = qrRaceCashier($branch);
    $product = Product::factory()->create(['default_price' => '95.00']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 10]);
    $session = CustomerQrSession::factory()->for($branch)->create();

    return [$branch, $user, $product, $balance, $store, $session];
}

function qrRaceCashier(Branch $branch): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

/** @return array<string, mixed> */
function qrRacePayload(Product $product, ?string $key = null): array
{
    return ['idempotency_key' => $key ?? (string) Str::uuid(), 'order_type' => 'take_out',
        'items' => [['product_id' => $product->id, 'quantity' => 2, 'notes' => 'Less salt', 'modifiers' => []]]];
}

function qrRaceEffects(Order $order, int $payments, int $movements, int $tickets): void
{
    qrVerify(Payment::where('order_id', $order->id)->count() === $payments, 'Wrong payment effects');
    qrVerify(InventoryMovement::where('order_id', $order->id)->count() === $movements, 'Wrong inventory effects');
    qrVerify(KitchenTicket::where('order_id', $order->id)->count() === $tickets, 'Wrong kitchen effects');
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
qrVerify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});
qrVerify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
config(['cache.default' => 'array', 'session.driver' => 'array', 'queue.default' => 'sync', 'broadcasting.default' => 'null', 'hashing.bcrypt.rounds' => 4]);

if (($argv[1] ?? '') === '--sqlite-migrations') {
    $database = tempnam(sys_get_temp_dir(), 'pong-qr-');
    config(['database.default' => 'sqlite', 'database.connections.sqlite.url' => null, 'database.connections.sqlite.database' => $database]);
    DB::purge('sqlite');
    try {
        qrVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'SQLite fresh failed');
        qrVerify(Schema::hasTable('customer_qr_sessions'), 'QR session table missing');
        $originalChecks = substr_count(strtolower(DB::selectOne("SELECT sql FROM sqlite_master WHERE name = 'orders'")->sql), 'check');
        qrVerify($originalChecks >= 9, 'SQLite migration lost existing Order CHECK constraints');
        $legacy = Order::factory()->create(['order_number' => '1043', 'reference_number' => 'MAIN-260919-1043']);
        qrVerify(Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true, '--no-interaction' => true]) === 0, 'SQLite rollback failed');
        qrVerify(! Schema::hasTable('customer_qr_sessions') && ! Schema::hasColumn('orders', 'public_tracking_id'), 'SQLite rollback left QR fields');
        qrVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'SQLite reapply failed');
        qrVerify(substr_count(strtolower(DB::selectOne("SELECT sql FROM sqlite_master WHERE name = 'orders'")->sql), 'check') === $originalChecks, 'SQLite rollback/reapply lost Order CHECK constraints');
        qrVerify($legacy->fresh()->order_number === '1043' && $legacy->fresh()->reference_number === 'MAIN-260919-1043', 'SQLite historical identity rewritten');
        echo 'SQLITE PASS: isolated fresh / rollback / reapply with existing Order CHECK constraints.'.PHP_EOL;
    } finally {
        DB::disconnect('sqlite');
        unlink($database);
    }
    exit(0);
}

$connection = config('database.connections.pgsql');
qrVerify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null required.');
qrVerify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback hosts are allowed.');
qrVerify(ctype_digit((string) $connection['port']), 'A numeric local port is required.');
$worker = ($argv[1] ?? '') === '--worker';
$schema = $worker ? ($argv[2] ?? '') : 'qr_'.bin2hex(random_bytes(8));
qrVerify(preg_match('/\Aqr_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema');
config(['database.connections.pgsql.search_path' => $schema,
    'database.connections.qr_observer' => [...$connection, 'search_path' => 'pg_catalog']]);
DB::purge('pgsql');
$observer = DB::connection('qr_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
qrVerify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'Server must report loopback address.');

if ($worker) {
    qrVerify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Worker schema isolation failed.');
    DB::statement("SET lock_timeout = '25s'");
    DB::statement("SET statement_timeout = '30s'");
    $job = json_decode(base64_decode($argv[3]), true, flags: JSON_THROW_ON_ERROR);
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.$job['tag']]);
    $branch = Branch::findOrFail($job['branch']);
    $user = User::findOrFail($job['user']);
    try {
        $result = match ($job['mode']) {
            'submit' => app(SubmitCustomerQrOrder::class)->execute($branch, CustomerQrSession::findOrFail($job['session']), $job['payload']),
            'load' => app(LoadCustomerQrOrder::class)->execute($user, $branch, Order::findOrFail($job['order'])),
            'cancel' => app(CancelLoadedCustomerQrOrder::class)->execute($user, $branch, Order::findOrFail($job['order'])),
            'restore' => app(RestoreCustomerQrOrder::class)->execute($user, $branch, Order::findOrFail($job['order'])),
            'archive' => app(ArchiveCustomerQrOrder::class)->execute(Order::findOrFail($job['order'])),
            'now' => app(PayNowOrder::class)->execute($user, $branch, $job['payload']),
            'later' => app(CommitPayLaterOrder::class)->execute($user, $branch, Order::findOrFail($job['order']), $job['payload']),
        };
        $output = ['status' => $result === false ? 'unchanged' : 'success', 'id' => $result instanceof Order ? $result->id : null];
    } catch (HttpException $exception) {
        $output = ['status' => 'rejected', 'http' => $exception->getStatusCode()];
    } catch (ValidationException $exception) {
        $output = ['status' => 'rejected', 'http' => 422, 'errors' => $exception->errors()];
    }
    qrVerify(DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction(), 'Worker transaction did not close');
    echo json_encode([...$output, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/**
 * Parent holds a real row lock; every independent worker must be observed waiting before release.
 *
 * @param  array<string, mixed>  $connection
 * @param  list<array<string, mixed>>  $jobs
 * @return list<array<string, mixed>>
 */
function qrOverlap(string $schema, array $connection, object $observer, array $jobs, callable $barrier, ?callable $beforeRelease = null): array
{
    $environment = ['APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode']];
    $processes = [];
    DB::beginTransaction();
    try {
        $barrier();
        foreach ($jobs as $index => $job) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema,
                base64_encode(json_encode([...$job, 'tag' => (string) $index], JSON_THROW_ON_ERROR))], dirname(__DIR__), $environment, timeout: 40);
            $processes[] = $process;
            $process->start();
        }
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $waiting = $observer->select("SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0", [$schema.'_%']);
            if (count($waiting) === count($jobs)) {
                break;
            }
            foreach ($processes as $process) {
                qrVerify($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
            }
            qrVerify(hrtime(true) < $deadline, 'Workers did not overlap at the barrier');
            usleep(10_000);
        } while (true);
        if ($beforeRelease !== null) {
            $beforeRelease();
        }
        DB::commit();
        $results = [];
        foreach ($processes as $process) {
            qrVerify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
            $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }
        qrVerify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers shared a connection');

        return $results;
    } finally {
        while (DB::transactionLevel() > 0) {
            DB::rollBack();
        }
        foreach ($processes as $process) {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    qrVerify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed');
    qrVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'PostgreSQL fresh migration failed');
    qrVerify(Artisan::call('migrate:rollback', ['--step' => 3, '--force' => true, '--no-interaction' => true]) === 0, 'PostgreSQL rollback failed');
    qrVerify(! Schema::hasTable('customer_qr_sessions'), 'PostgreSQL rollback left QR schema');
    qrVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'PostgreSQL reapply failed');
    $legacy = Order::factory()->create(['order_number' => '1043', 'reference_number' => 'MAIN-260919-1043']);
    qrVerify(Artisan::call('migrate:rollback', ['--step' => 2, '--force' => true, '--no-interaction' => true]) === 0, 'Historical migration rollback failed');
    qrVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Historical migration reapply failed');
    qrVerify($legacy->fresh()->order_number === '1043' && $legacy->fresh()->reference_number === 'MAIN-260919-1043', 'Historical identity rewritten');
    (new RbacSeeder)->run();
    echo 'POSTGRES MIGRATION PASS: isolated fresh / rollback / reapply.'.PHP_EOL;
    [$branch, $user, $product, $stock, , $session] = qrRaceFixture();
    $job = ['mode' => 'submit', 'branch' => $branch->id, 'user' => $user->id, 'session' => $session->id, 'payload' => qrRacePayload($product)];
    $results = qrOverlap($schema, $connection, $observer, [$job, $job], fn () => Branch::whereKey($branch->id)->lockForUpdate()->sole());
    qrVerify(array_column($results, 'status') === ['success', 'success'] && $results[0]['id'] === $results[1]['id'], 'Duplicate submit did not recover one order');
    $order = Order::where('branch_id', $branch->id)->sole();
    qrRaceEffects($order, 0, 0, 0);
    qrVerify($stock->fresh()->on_hand === 10, 'Submit deducted stock');
    echo 'A PASS: overlapping duplicate submission creates one order, no operational effects.'.PHP_EOL;

    $other = qrRaceCashier($branch);
    $job = ['mode' => 'load', 'branch' => $branch->id, 'user' => $user->id, 'order' => $order->id];
    $results = qrOverlap($schema, $connection, $observer, [$job, [...$job, 'user' => $other->id]], fn () => Branch::whereKey($branch->id)->lockForUpdate()->sole());
    $statuses = array_column($results, 'status');
    sort($statuses);
    qrVerify($statuses === ['rejected', 'success'], 'LOAD race did not have one winner');
    qrVerify(in_array($order->fresh()->loaded_by_user_id, [$user->id, $other->id], true), 'No durable LOAD owner');
    qrRaceEffects($order, 0, 0, 0);
    echo 'B PASS: two cashiers LOAD concurrently; one claim, no operational effects.'.PHP_EOL;

    [$branch, $user, $product, , , $session] = qrRaceFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrRacePayload($product));
    $order->update(['submitted_at' => now()->subMinutes(31)]);
    $job = ['mode' => 'load', 'branch' => $branch->id, 'user' => $user->id, 'order' => $order->id];
    qrOverlap($schema, $connection, $observer, [$job, [...$job, 'mode' => 'archive']], fn () => Order::whereKey($order->id)->lockForUpdate()->sole());
    $order->refresh();
    qrVerify(($order->commercial_status === CommercialStatus::Submitted && $order->loaded_by_user_id === $user->id && $order->archived_at === null)
        || ($order->commercial_status === CommercialStatus::ArchivedUnclaimed && $order->loaded_by_user_id === null && $order->archived_at !== null), 'LOAD/archive produced incompatible final state');
    qrRaceEffects($order, 0, 0, 0);
    echo 'C PASS: LOAD versus stale archive serializes to one legal state.'.PHP_EOL;

    foreach (['now', 'later'] as $mode) {
        [$branch, $user, $product, $stock, , $session] = qrRaceFixture();
        $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrRacePayload($product));
        app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
        $payload = ['idempotency_key' => (string) Str::uuid()];
        if ($mode === 'now') {
            $payload += ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
        }
        $job = ['mode' => $mode, 'branch' => $branch->id, 'user' => $user->id, 'order' => $order->id, 'payload' => $payload];
        $results = qrOverlap($schema, $connection, $observer, [$job, $job], fn () => Branch::whereKey($branch->id)->lockForUpdate()->sole());
        qrVerify(array_column($results, 'status') === ['success', 'success'], 'Duplicate QR commercial commit did not replay');
        qrRaceEffects($order, $mode === 'now' ? 1 : 0, 1, 1);
        qrVerify($stock->fresh()->on_hand === 8 && $order->fresh()->order_number !== null && $order->fresh()->qr_sequence === $order->qr_sequence, 'Stock or order identity changed incorrectly');
        echo strtoupper($mode).' PASS: duplicate QR commit deducts once and creates one ticket.'.PHP_EOL;
    }

    [$branch, $user, $product, , , $session] = qrRaceFixture();
    $sessionB = CustomerQrSession::factory()->for($branch)->create();
    $job = ['mode' => 'submit', 'branch' => $branch->id, 'user' => $user->id, 'session' => $session->id, 'payload' => qrRacePayload($product)];
    qrOverlap($schema, $connection, $observer, [$job, [...$job, 'session' => $sessionB->id, 'payload' => qrRacePayload($product)]], fn () => Branch::whereKey($branch->id)->lockForUpdate()->sole());
    qrVerify(Order::where('branch_id', $branch->id)->orderBy('qr_sequence')->pluck('qr_sequence')->all() === [1, 2], 'Provisional numbering duplicated');
    qrVerify(Order::where('branch_id', $branch->id)->whereNotNull('order_number')->doesntExist(), 'Submit consumed official identity');
    echo 'G PASS: concurrent submissions receive QR-01 and QR-02 without official identity.'.PHP_EOL;

    foreach (['now', 'later'] as $mode) {
        [$branch, $user, $product, $stock, , $session] = qrRaceFixture();
        $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrRacePayload($product));
        app(LoadCustomerQrOrder::class)->execute($user, $branch, $order);
        $payload = ['idempotency_key' => (string) Str::uuid()];
        if ($mode === 'now') {
            $payload += ['draft_order_id' => $order->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
        }
        $job = ['mode' => $mode, 'branch' => $branch->id, 'user' => $user->id, 'order' => $order->id, 'payload' => $payload];
        $results = qrOverlap($schema, $connection, $observer, [$job, [...$job, 'mode' => 'cancel']], fn () => Order::whereKey($order->id)->lockForUpdate()->sole());
        $statuses = array_column($results, 'status');
        sort($statuses);
        qrVerify($statuses === ['rejected', 'success'], 'Cancel/commit did not have exactly one winner');
        $committed = $order->fresh()->committed_at !== null;
        qrVerify(($order->fresh()->order_number !== null) === $committed, 'Partial identity allocation');
        qrRaceEffects($order, $committed && $mode === 'now' ? 1 : 0, $committed ? 1 : 0, $committed ? 1 : 0);
        qrVerify($stock->fresh()->on_hand === ($committed ? 8 : 10), 'Cancel/commit stock mismatch');
        echo 'H PASS: Cancel LOAD versus '.$mode.' commits exactly one legal final state.'.PHP_EOL;

        [$branch, $user, $product, $stock, , $session] = qrRaceFixture();
        $other = qrRaceCashier($branch);
        $first = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrRacePayload($product));
        $second = app(SubmitCustomerQrOrder::class)->execute($branch, CustomerQrSession::factory()->for($branch)->create(), qrRacePayload($product));
        app(LoadCustomerQrOrder::class)->execute($user, $branch, $first);
        app(LoadCustomerQrOrder::class)->execute($other, $branch, $second);
        $jobs = [];
        foreach ([[$first, $user], [$second, $other]] as [$candidate, $cashier]) {
            $payload = ['idempotency_key' => (string) Str::uuid()];
            if ($mode === 'now') {
                $payload += ['draft_order_id' => $candidate->id, 'payment_method' => 'cash', 'cash_received' => '200.00'];
            }
            $jobs[] = ['mode' => $mode, 'branch' => $branch->id, 'user' => $cashier->id, 'order' => $candidate->id, 'payload' => $payload];
        }
        $results = qrOverlap($schema, $connection, $observer, $jobs, fn () => Branch::whereKey($branch->id)->lockForUpdate()->sole());
        qrVerify(array_column($results, 'status') === ['success', 'success'], 'Concurrent commits failed');
        $orders = Order::where('branch_id', $branch->id)->get();
        qrVerify($orders->pluck('order_number')->unique()->count() === 2 && $orders->pluck('reference_number')->unique()->count() === 2, 'Duplicate official identities');
        qrVerify($first->fresh()->qr_sequence === 1 && $second->fresh()->qr_sequence === 2, 'Provisional identity mutated');
        qrRaceEffects($first, $mode === 'now' ? 1 : 0, 1, 1);
        qrRaceEffects($second, $mode === 'now' ? 1 : 0, 1, 1);
        qrVerify($stock->fresh()->on_hand === 6, 'Concurrent commits deducted incorrectly');
        echo 'I PASS: concurrent '.$mode.' official short numbers and daily references are unique; stock/tickets exactly once.'.PHP_EOL;
    }

    [$branch, $user, $product, , $store, $session] = qrRaceFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrRacePayload($product));
    app(ArchiveCustomerQrOrder::class)->execute($order, 'cashier_archived');
    $session->update(['active_order_id' => null]);
    $job = ['mode' => 'restore', 'branch' => $branch->id, 'user' => $user->id, 'order' => $order->id];
    $submit = ['mode' => 'submit', 'branch' => $branch->id, 'user' => $user->id, 'session' => $session->id, 'payload' => qrRacePayload($product)];
    $results = qrOverlap($schema, $connection, $observer, [$job, $submit], fn () => Branch::whereKey($branch->id)->lockForUpdate()->sole());
    $statuses = array_column($results, 'status');
    sort($statuses);
    qrVerify($statuses === ['rejected', 'success'], 'Restore allowed conflicting active order');
    qrVerify(Order::where('customer_qr_session_id', $session->id)->where('commercial_status', CommercialStatus::Submitted)->count() === 1, 'Multiple active customer orders');
    qrRaceEffects($order, 0, 0, 0);
    echo 'J PASS: Restore versus new submission preserves one active anonymous order.'.PHP_EOL;

    [$branch, $user, $product, , $store, $session] = qrRaceFixture();
    $order = app(SubmitCustomerQrOrder::class)->execute($branch, $session, qrRacePayload($product));
    app(ArchiveCustomerQrOrder::class)->execute($order, 'cashier_archived');
    $job = ['mode' => 'restore', 'branch' => $branch->id, 'user' => $user->id, 'order' => $order->id];
    $results = qrOverlap($schema, $connection, $observer, [$job], fn () => StoreSession::whereKey($store->id)->lockForUpdate()->sole(), fn () => $store->update(['status' => 'closed']));
    qrVerify($results[0]['status'] === 'rejected', 'Restore crossed Store Close');
    qrRaceEffects($order, 0, 0, 0);
    echo 'K PASS: Restore respects the exclusive Store Close boundary.'.PHP_EOL;

    foreach (['store', 'branch'] as $boundary) {
        [$branch, $user, $product, $stock, $store, $session] = qrRaceFixture();
        $job = ['mode' => 'submit', 'branch' => $branch->id, 'user' => $user->id, 'session' => $session->id, 'payload' => qrRacePayload($product)];
        $results = qrOverlap($schema, $connection, $observer, [$job],
            fn () => $boundary === 'store' ? StoreSession::whereKey($store->id)->lockForUpdate()->sole() : Branch::whereKey($branch->id)->lockForUpdate()->sole(),
            fn () => $boundary === 'store' ? $store->update(['status' => 'closed']) : $branch->update(['status' => 'inactive']));
        qrVerify($results[0]['status'] === 'rejected' && Order::where('branch_id', $branch->id)->count() === 0 && $stock->fresh()->on_hand === 10, 'State change admitted an invalid QR order');
        echo 'F PASS: '.$boundary.' change while submit waits rejects without an order.'.PHP_EOL;
    }
} finally {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        qrVerify($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Schema cleanup failed');
        echo 'CLEANUP PASS '.$schema.PHP_EOL;
    }
}
