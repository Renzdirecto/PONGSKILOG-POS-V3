<?php

/**
 * Opt-in: php tests/verify-inventory-postgres.php with explicit local DB_* and DB_URL=null.
 * Uses independent application processes and observed PostgreSQL lock waits, not timing
 * assumptions. Only a random phase4c_* schema is migrated and removed. No public writes.
 * Outside Pest's RefreshDatabase suite so workers can see committed fixtures.
 */

use App\Actions\Inventory\ApplyInventoryMovement;
use App\Enums\InventoryMovementType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<Process> $processes */
function awaitInventoryLocks(Connection $observer, string $schema, string $scenario, array $processes): void
{
    $deadline = hrtime(true) + 10_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid, query, pg_blocking_pids(pid) AS blockers FROM pg_stat_activity
             WHERE datname = current_database() AND application_name LIKE ?
             AND state = 'active' AND wait_event_type = 'Lock'
             AND query LIKE 'select%for update' AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'_'.$scenario.'_%'],
        );
        if (count($waiting) === count($processes)) {
            $queries = array_column($waiting, 'query');
            verify(count(array_unique(array_column($waiting, 'pid'))) === count($processes), 'Expected independent database connections.');
            verify(collect($queries)->every(fn (string $query): bool => str_contains($query, '"branch_inventory"') || str_contains($query, '"branch_products"')), 'Unexpected lock target.');
            if ($scenario !== 'first') {
                verify(collect($queries)->contains(fn (string $query): bool => str_contains($query, '"branch_inventory"')), 'No worker reached the locked stock row.');
            }
            echo 'OVERLAP '.$scenario.' '.json_encode($waiting, JSON_THROW_ON_ERROR).PHP_EOL;

            return;
        }
        foreach ($processes as $process) {
            verify($process->isRunning(), 'Worker exited before lock overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verify(hrtime(true) < $deadline, 'Timed out waiting for inventory lock overlap.');
        usleep(10_000);
    } while (true);
}

/** @return array{pid: int, outcome: string, transaction_level: int, pdo_transaction: bool, connection_usable: bool} */
function inventoryResult(Process $process): array
{
    verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
    $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    verify($result['transaction_level'] === 0 && $result['pdo_transaction'] === false && $result['connection_usable'] === true, 'Worker connection did not recover cleanly.');

    return $result;
}

function verifyInventory(Branch $branch, Product $product, int $onHand, int $version, int $movements, User $actor): void
{
    $balance = BranchInventory::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->sole();
    $ledger = InventoryMovement::query()->where('branch_id', $branch->id)->where('product_id', $product->id)->get();
    verify($balance->on_hand === $onHand && $balance->version === $version, 'Incorrect balance/version.');
    verify($ledger->count() === $movements && (int) $ledger->sum('quantity_delta') === $onHand, 'Balance and ledger diverged.');
    verify($ledger->every(fn (InventoryMovement $movement): bool => $movement->created_by_user_id === $actor->id
        && $movement->reason !== null && $movement->created_at !== null
        && $movement->movement_type === InventoryMovementType::ManualAdjustment), 'Missing movement traceability.');
    echo 'STATE '.json_encode(['branch' => $branch->code, 'product' => $product->name, 'on_hand' => $onHand, 'version' => $version, 'movements' => $movements], JSON_THROW_ON_ERROR).PHP_EOL;
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verify(! $app->configurationIsCached(), 'Clear cached configuration before local verification.');
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
$schema = $worker ? ($argv[2] ?? '') : 'phase4c_'.bin2hex(random_bytes(8));
verify(preg_match('/\Aphase4c_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase4c_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase4c_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host, inet_server_port() AS port');
verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

if ($worker) {
    verify($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 1, 'Missing isolated schema.');
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Worker schema isolation failed.');
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '20s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.$argv[3]]);
    $branch = Branch::query()->findOrFail($argv[4]);
    $product = Product::query()->findOrFail($argv[5]);
    $actor = User::query()->findOrFail($argv[7]);
    $outcome = 'committed';
    try {
        app(ApplyInventoryMovement::class)->execute($branch, $product, InventoryMovementType::ManualAdjustment, (int) $argv[6], 'Concurrent verification '.$argv[3], $actor);
    } catch (ValidationException $exception) {
        verify($exception->errors() === ['quantity_delta' => ['Insufficient stock for this inventory movement.']], 'Unexpected validation failure.');
        $outcome = 'insufficient_stock';
    }
    $transactionLevel = DB::transactionLevel();
    $pdoTransaction = DB::connection()->getPdo()->inTransaction();
    $probe = DB::selectOne('SELECT pg_backend_pid() AS pid, 1 AS usable');
    verify($transactionLevel === 0 && ! $pdoTransaction && $probe->usable === 1, 'Worker left an unusable connection or transaction.');
    echo json_encode(['pid' => $probe->pid, 'outcome' => $outcome, 'transaction_level' => $transactionLevel,
        'pdo_transaction' => $pdoTransaction, 'connection_usable' => true], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$processes = [];
$createdSchema = false;

try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');
    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $actor = User::factory()->create();
    $action = app(ApplyInventoryMovement::class);
    $start = function (string $label, Branch $branch, Product $product, int $delta) use ($schema, $connection, $actor, &$processes): Process {
        $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema, $label, $branch->id, $product->id, (string) $delta, (string) $actor->id], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 25);
        $processes[] = $process;
        $process->start();

        return $process;
    };

    $last = Product::factory()->create(['name' => 'Last unit']);
    BranchProduct::factory()->for($main)->for($last)->create(['tracks_inventory' => true]);
    $initialMovement = $action->execute($main, $last, InventoryMovementType::ManualAdjustment, 1, 'Initial stock', $actor)->refresh()->getAttributes();

    /** One worker waits on stock while the other waits on its configuration lock. */
    DB::beginTransaction();
    BranchInventory::query()->where('branch_id', $main->id)->where('product_id', $last->id)->lockForUpdate()->sole();
    $lastWorkers = [$start('last_a', $main, $last, -1), $start('last_b', $main, $last, -1)];
    awaitInventoryLocks($observer, $schema, 'last', $lastWorkers);
    DB::commit();
    $lastResults = array_map(inventoryResult(...), $lastWorkers);
    $outcomes = array_column($lastResults, 'outcome');
    sort($outcomes);
    verify($outcomes === ['committed', 'insufficient_stock'], 'Last unit must have exactly one winner and one insufficient-stock rejection.');
    verifyInventory($main, $last, 0, 2, 2, $actor);
    verify(InventoryMovement::query()->where('product_id', $last->id)->where('quantity_delta', -1)->count() === 1, 'Expected exactly one new deduction.');
    verify(InventoryMovement::query()->findOrFail($initialMovement['id'])->getAttributes() === $initialMovement, 'Initial ledger entry was rewritten.');
    echo 'LAST UNIT PASS '.json_encode($lastResults, JSON_THROW_ON_ERROR).PHP_EOL;

    $first = Product::factory()->create(['name' => 'First balance']);
    $configuration = BranchProduct::factory()->for($main)->for($first)->create(['tracks_inventory' => true]);
    verify(! BranchInventory::query()->where('product_id', $first->id)->exists(), 'First-balance fixture must have no balance.');

    /** The existing configuration lock serializes first creation before insertOrIgnore. */
    DB::beginTransaction();
    BranchProduct::query()->whereKey($configuration->id)->lockForUpdate()->sole();
    $firstWorkers = [$start('first_a', $main, $first, 5), $start('first_b', $main, $first, 3)];
    awaitInventoryLocks($observer, $schema, 'first', $firstWorkers);
    DB::commit();
    $firstResults = array_map(inventoryResult(...), $firstWorkers);
    verify(array_column($firstResults, 'outcome') === ['committed', 'committed'], 'Both first-balance additions must commit.');
    verifyInventory($main, $first, 8, 2, 2, $actor);
    verify(InventoryMovement::query()->where('product_id', $first->id)->orderBy('quantity_delta')->pluck('quantity_delta')->all() === [3, 5], 'First-balance deltas were lost.');
    echo 'FIRST BALANCE PASS '.json_encode($firstResults, JSON_THROW_ON_ERROR).PHP_EOL;

    $isolated = Product::factory()->create(['name' => 'Branch isolation']);
    foreach ([[$main, 10], [$qave, 4]] as [$branch, $quantity]) {
        BranchProduct::factory()->for($branch)->for($isolated)->create(['tracks_inventory' => true]);
        $action->execute($branch, $isolated, InventoryMovementType::ManualAdjustment, $quantity, 'Initial stock', $actor);
    }
    $action->execute($main, $isolated, InventoryMovementType::ManualAdjustment, -3, 'MAIN only', $actor);
    verifyInventory($main, $isolated, 7, 2, 2, $actor);
    verifyInventory($qave, $isolated, 4, 1, 1, $actor);

    DB::beginTransaction();
    BranchInventory::query()->where('branch_id', $main->id)->where('product_id', $isolated->id)->lockForUpdate()->sole();
    $mainWorker = $start('branches_main', $main, $isolated, -1);
    awaitInventoryLocks($observer, $schema, 'branches', [$mainWorker]);
    $qaveResult = inventoryResult($start('independent_qave', $qave, $isolated, 3));
    verify($qaveResult['outcome'] === 'committed' && $mainWorker->isRunning(), 'QAVE must finish while MAIN is still blocked.');
    verifyInventory($main, $isolated, 7, 2, 2, $actor);
    verifyInventory($qave, $isolated, 7, 2, 2, $actor);
    DB::commit();
    $mainResult = inventoryResult($mainWorker);
    verify($mainResult['outcome'] === 'committed', 'MAIN must finish after its lock is released.');
    verifyInventory($main, $isolated, 6, 3, 3, $actor);
    verifyInventory($qave, $isolated, 7, 2, 2, $actor);
    verify(! BranchInventory::query()->where('on_hand', '<', 0)->exists(), 'Negative stock detected.');
    verify(DB::transactionLevel() === 0 && ! DB::connection()->getPdo()->inTransaction(), 'Parent left an open transaction.');
    echo 'PASS: independent-process last-unit race, first-balance creation, append-only traceability, branch isolation, independent branch progress and fresh PostgreSQL migrations.'.PHP_EOL;
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
        verify($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Schema cleanup failed.');
        echo 'CLEANUP PASS: isolated schema removed and absence confirmed: '.$schema.PHP_EOL;
    }
}
