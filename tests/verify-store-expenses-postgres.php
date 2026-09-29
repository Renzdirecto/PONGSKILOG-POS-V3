<?php

/**
 * Opt-in real PostgreSQL verification: php tests/verify-store-expenses-postgres.php
 *
 * Creates and removes only a random phase14_* schema on a loopback PostgreSQL
 * server. The configured public schema is never migrated, truncated, or written.
 */

use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\CurrentStoreSessionExpenses;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function verifyPhase14(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<Process> $processes */
function awaitPhase14Locks(Connection $observer, string $applicationPrefix, array $processes): void
{
    $deadline = hrtime(true) + 10_000_000_000;

    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity
             WHERE datname = current_database()
             AND application_name LIKE ?
             AND state = 'active'
             AND wait_event_type = 'Lock'
             AND cardinality(pg_blocking_pids(pid)) > 0",
            [$applicationPrefix.'%'],
        );
        if (count($waiting) === count($processes)) {
            return;
        }
        foreach ($processes as $process) {
            verifyPhase14($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verifyPhase14(hrtime(true) < $deadline, 'Timed out waiting for concurrent advisory-lock requests.');
        usleep(10_000);
    } while (true);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verifyPhase14(! $app->configurationIsCached(), 'Clear cached configuration before local verification.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
verifyPhase14(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
verifyPhase14(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
verifyPhase14(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');
verifyPhase14(ctype_digit((string) $connection['port']), 'A single numeric local port is required.');

$worker = ($argv[1] ?? null) === '--worker';
$schema = $worker ? ($argv[2] ?? '') : 'phase14_'.bin2hex(random_bytes(8));
verifyPhase14(preg_match('/\Aphase14_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase14_admin' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase14_admin');
$identity = $observer->selectOne('SELECT current_database() AS database, host(inet_server_addr()) AS host, inet_server_port() AS port');
verifyPhase14(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

if ($worker) {
    verifyPhase14($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 1, 'Missing isolated schema.');
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '20s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$argv[3]]);

    $paymentSource = $argv[9] ?? 'cash';
    $restock = ($argv[10] ?? '1') === '1';

    try {
        $expense = app(RecordStoreSessionExpense::class)->execute(
            User::query()->findOrFail($argv[4]),
            Branch::query()->findOrFail($argv[5]),
            array_filter([
                'idempotency_key' => $argv[6],
                'description' => 'Concurrent ice restock',
                'amount' => $argv[7],
                'payment_source' => $paymentSource,
                'note' => null,
                'restock' => $restock,
                'product_id' => $restock ? $argv[8] : null,
                'quantity' => $restock ? 3 : null,
            ], fn (mixed $value): bool => $value !== null),
        );
        echo json_encode(['status' => 200, 'expense_id' => $expense->id, 'amount' => $expense->amount], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (HttpExceptionInterface $exception) {
        echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (ValidationException) {
        echo json_encode(['status' => 422], JSON_THROW_ON_ERROR).PHP_EOL;
    }

    exit(0);
}

$processes = [];
$createdSchema = false;

try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verifyPhase14(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verifyPhase14(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');

    $columns = collect(DB::select(
        'SELECT column_name, data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
        [$schema, 'store_session_expenses'],
    ))->keyBy('column_name');
    verifyPhase14($columns['id']->data_type === 'uuid', 'Expense ID must be UUID.');
    verifyPhase14($columns['amount']->data_type === 'numeric' && $columns['amount']->numeric_precision === 14 && $columns['amount']->numeric_scale === 2, 'Expense amount must be numeric(14,2).');
    $constraints = collect(DB::select(
        'SELECT conname, contype, confdeltype, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = ?',
        [$schema],
    ))->keyBy('conname');
    verifyPhase14(isset($constraints['store_session_expenses_idempotency_key_unique']), 'Idempotency uniqueness is missing.');
    verifyPhase14(($constraints['inventory_movements_expense_foreign']->confdeltype ?? null) === 'r', 'Inventory expense FK must restrict deletes.');
    verifyPhase14(str_contains($constraints->first(fn (object $constraint): bool => str_contains($constraint->definition, 'amount >'))?->definition ?? '', 'amount >'), 'Positive amount constraint is missing.');

    verifyPhase14(Artisan::call('migrate:rollback', ['--step' => count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_23_032757')), '--force' => true, '--no-interaction' => true]) === 0, 'Phase 14 rollback failed.');
    verifyPhase14(! DB::getSchemaBuilder()->hasTable('store_session_expenses'), 'Expense table survived rollback.');
    verifyPhase14(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 14 reapply failed.');
    echo 'MIGRATION PASS: fresh, constraints, rollback, and reapply.'.PHP_EOL;

    verifyPhase14(Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true, '--no-interaction' => true]) === 0, 'RBAC seed failed.');
    $branch = Branch::factory()->create();
    $cashier = User::factory()->create();
    $cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $cashier->branches()->attach($branch, ['is_active' => true]);
    StoreSession::factory()->for($branch)->create();
    $product = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 5]);

    $startRace = function (string $label, string $key, array $amounts) use ($schema, $connection, $cashier, $branch, $product, &$processes): array {
        DB::beginTransaction();
        DB::select('SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$branch->id.':'.$key]);
        $workers = [];
        foreach ($amounts as $index => $amount) {
            $application = $schema.'_'.$label.'_'.$index;
            $process = new Process([
                PHP_BINARY, __FILE__, '--worker', $schema, $application, (string) $cashier->id,
                $branch->id, $key, $amount, $product->id,
            ], dirname(__DIR__), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
                'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
            ], timeout: 25);
            $processes[] = $process;
            $workers[] = $process;
            $process->start();
        }
        awaitPhase14Locks(DB::connection('phase14_admin'), $schema.'_'.$label.'_', $workers);
        DB::commit();

        return array_map(function (Process $process): array {
            verifyPhase14($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    };

    $exactKey = (string) Str::uuid();
    $exactResults = $startRace('exact', $exactKey, ['125.50', '125.50']);
    verifyPhase14(array_column($exactResults, 'status') === [200, 200], 'Exact replay workers must both resolve successfully.');
    verifyPhase14(count(array_unique(array_column($exactResults, 'expense_id'))) === 1, 'Exact replay returned different expenses.');
    verifyPhase14(StoreSessionExpense::query()->where('idempotency_key', $exactKey)->count() === 1, 'Exact replay duplicated the expense.');
    verifyPhase14(InventoryMovement::query()->count() === 1 && AuditLog::query()->where('action', 'store_expense_recorded')->count() === 1, 'Exact replay duplicated a side effect.');
    verifyPhase14(BranchInventory::query()->where('product_id', $product->id)->sole()->on_hand === 8, 'Exact replay changed stock more than once.');
    echo 'EXACT REPLAY PASS: one expense, movement, stock delta, and audit.'.PHP_EOL;

    $conflictKey = (string) Str::uuid();
    $conflictResults = $startRace('conflict', $conflictKey, ['200.00', '201.00']);
    verifyPhase14(collect($conflictResults)->pluck('status')->sort()->values()->all() === [200, 409], 'Changed-intent race must have one winner and one conflict.');
    verifyPhase14(StoreSessionExpense::query()->where('idempotency_key', $conflictKey)->count() === 1, 'Changed-intent race duplicated the expense.');
    verifyPhase14(InventoryMovement::query()->count() === 2 && AuditLog::query()->where('action', 'store_expense_recorded')->count() === 2, 'Changed-intent conflict leaked a side effect.');
    verifyPhase14(BranchInventory::query()->where('product_id', $product->id)->sole()->on_hand === 11, 'Changed-intent conflict changed stock more than once.');
    echo 'CONFLICT RACE PASS: one winner, one 409, and no duplicate side effects.'.PHP_EOL;

    DB::beginTransaction();
    BranchInventory::query()->where('product_id', $product->id)->lockForUpdate()->sole();
    $sameProductWorkers = [];
    foreach ([0, 1] as $index) {
        $application = $schema.'_same_product_'.$index;
        $process = new Process([
            PHP_BINARY, __FILE__, '--worker', $schema, $application, (string) $cashier->id,
            $branch->id, (string) Str::uuid(), '75.00', $product->id,
        ], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 25);
        $processes[] = $process;
        $sameProductWorkers[] = $process;
        $process->start();
    }
    awaitPhase14Locks($observer, $schema.'_same_product_', $sameProductWorkers);
    DB::commit();
    foreach ($sameProductWorkers as $process) {
        verifyPhase14($process->wait() === 0, 'Same-product worker failed: '.$process->getErrorOutput().$process->getOutput());
        verifyPhase14(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'] === 200, 'Same-product restock failed.');
    }
    verifyPhase14(BranchInventory::query()->where('product_id', $product->id)->sole()->on_hand === 17, 'Concurrent same-product restocks lost an increment.');
    echo 'SAME PRODUCT PASS: two independent restocks both incremented stock.'.PHP_EOL;

    $secondProduct = Product::factory()->create();
    BranchProduct::factory()->for($branch)->for($secondProduct)->create(['tracks_inventory' => true]);
    BranchInventory::factory()->for($branch)->for($secondProduct)->create(['on_hand' => 10]);
    DB::beginTransaction();
    BranchInventory::query()->whereIn('product_id', [$product->id, $secondProduct->id])->orderBy('product_id')->lockForUpdate()->get();
    $differentProductWorkers = [];
    foreach ([$product, $secondProduct] as $index => $workerProduct) {
        $application = $schema.'_different_products_'.$index;
        $process = new Process([
            PHP_BINARY, __FILE__, '--worker', $schema, $application, (string) $cashier->id,
            $branch->id, (string) Str::uuid(), '80.00', $workerProduct->id,
        ], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 25);
        $processes[] = $process;
        $differentProductWorkers[] = $process;
        $process->start();
    }
    awaitPhase14Locks($observer, $schema.'_different_products_', $differentProductWorkers);
    DB::commit();
    foreach ($differentProductWorkers as $process) {
        verifyPhase14($process->wait() === 0, 'Different-product worker failed: '.$process->getErrorOutput().$process->getOutput());
        verifyPhase14(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'] === 200, 'Different-product restock failed.');
    }
    verifyPhase14(BranchInventory::query()->where('product_id', $product->id)->sole()->on_hand === 20, 'First independent product has the wrong balance.');
    verifyPhase14(BranchInventory::query()->where('product_id', $secondProduct->id)->sole()->on_hand === 13, 'Second independent product has the wrong balance.');
    echo 'DIFFERENT PRODUCTS PASS: no unnecessary deadlock and both balances advanced.'.PHP_EOL;

    $totalsBranch = Branch::factory()->create();
    $totalsCashier = User::factory()->create();
    $totalsCashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $totalsCashier->branches()->attach($totalsBranch, ['is_active' => true]);
    $totalsSession = StoreSession::factory()->for($totalsBranch)->create();
    DB::beginTransaction();
    StoreSession::query()->whereKey($totalsSession->id)->lockForUpdate()->sole();
    $totalsWorkers = [];
    foreach ([['cash', '200.00'], ['cashless', '500.00']] as $index => [$source, $amount]) {
        $application = $schema.'_expense_totals_'.$index;
        $process = new Process([
            PHP_BINARY, __FILE__, '--worker', $schema, $application, (string) $totalsCashier->id,
            $totalsBranch->id, (string) Str::uuid(), $amount, $product->id, $source, '0',
        ], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 25);
        $processes[] = $process;
        $totalsWorkers[] = $process;
        $process->start();
    }
    awaitPhase14Locks($observer, $schema.'_expense_totals_', $totalsWorkers);
    DB::commit();
    foreach ($totalsWorkers as $process) {
        verifyPhase14($process->wait() === 0, 'Expense-total worker failed: '.$process->getErrorOutput().$process->getOutput());
        verifyPhase14(json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'] === 200, 'Concurrent Cash/Cashless expense failed.');
    }
    $totals = app(CurrentStoreSessionExpenses::class)->for($totalsBranch, $totalsSession);
    verifyPhase14($totals['expense_totals'] === ['cash' => '200.00', 'cashless' => '500.00', 'total' => '700.00'], 'Concurrent Cash/Cashless totals were inaccurate.');
    verifyPhase14($totals['expense_count'] === 2, 'Concurrent Cash/Cashless expenses were not both retained.');
    echo 'EXPENSE TOTALS PASS: concurrent Cash/Cashless writes produced exact full-session totals.'.PHP_EOL;

    $session = StoreSession::query()->where('branch_id', $branch->id)->where('status', 'open')->sole();
    DB::beginTransaction();
    StoreSession::query()->whereKey($session->id)->lockForUpdate()->sole();
    $closeApplication = $schema.'_close_boundary_0';
    $closeWorker = new Process([
        PHP_BINARY, __FILE__, '--worker', $schema, $closeApplication, (string) $cashier->id,
        $branch->id, (string) Str::uuid(), '90.00', $product->id,
    ], dirname(__DIR__), [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
    ], timeout: 25);
    $processes[] = $closeWorker;
    $closeWorker->start();
    awaitPhase14Locks($observer, $schema.'_close_boundary_', [$closeWorker]);
    StoreSession::query()->whereKey($session->id)->update(['status' => 'closed', 'closed_at' => now(), 'closed_by_user_id' => $cashier->id]);
    DB::commit();
    verifyPhase14($closeWorker->wait() === 0, 'Close-boundary worker failed: '.$closeWorker->getErrorOutput().$closeWorker->getOutput());
    verifyPhase14(json_decode($closeWorker->getOutput(), true, flags: JSON_THROW_ON_ERROR)['status'] === 422, 'Expense crossed the exclusive Store Session close boundary.');
    verifyPhase14(StoreSessionExpense::query()->where('amount', '90.00')->doesntExist(), 'Expense committed after Store Session closure.');
    echo 'CLOSE BOUNDARY PASS: exclusive session close wins and waiting expense is rejected.'.PHP_EOL;
    echo 'PASS: PostgreSQL Phase 14 schema and concurrency invariants.'.PHP_EOL;
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
        verifyPhase14($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS: removed isolated schema '.$schema.PHP_EOL;
    }
}
