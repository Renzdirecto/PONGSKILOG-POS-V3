<?php

/**
 * Opt-in real PostgreSQL verification: php tests/verify-operations-postgres.php
 *
 * Creates and removes only a random phase16e_* schema on a loopback PostgreSQL server. The configured public schema
 * (the normal local development data) is never migrated, truncated, or written.
 */

use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Operations\ConfirmPamamalengke;
use App\Actions\Orders\PayNowOrder;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\OperationPlan;
use App\Models\OrderRecipeSnapshot;
use App\Models\PamamalengkePurchase;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\ExactQuantity;
use App\Support\OperationsSummary;
use App\Support\OperationsWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\OperationsScenario;

require dirname(__DIR__).'/vendor/autoload.php';

function verifyPhase16E(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<Process> $processes */
function awaitPhase16ELocks(Connection $observer, string $applicationPrefix, array $processes): void
{
    $deadline = hrtime(true) + 15_000_000_000;

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
            verifyPhase16E($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verifyPhase16E(hrtime(true) < $deadline, 'Timed out waiting for concurrent lock requests.');
        usleep(10_000);
    } while (true);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verifyPhase16E(! $app->configurationIsCached(), 'Clear cached configuration before local verification.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
verifyPhase16E(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
verifyPhase16E(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
verifyPhase16E(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');
verifyPhase16E(ctype_digit((string) $connection['port']), 'A single numeric local port is required.');

$worker = ($argv[1] ?? null) === '--worker';
$schema = $worker ? ($argv[2] ?? '') : 'phase16e_'.bin2hex(random_bytes(8));
verifyPhase16E(preg_match('/\Aphase16e_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase16e_admin' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase16e_admin');
$identity = $observer->selectOne('SELECT current_database() AS database, host(inet_server_addr()) AS host, inet_server_port() AS port');
verifyPhase16E(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

if ($worker) {
    verifyPhase16E($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 1, 'Missing isolated schema.');
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$argv[3]]);
    [$action, $userId, $branchId, $key] = [$argv[4], $argv[5], $argv[6], $argv[7]];
    $user = User::query()->findOrFail($userId);
    $branch = Branch::query()->findOrFail($branchId);
    session([ActiveBranchContext::SESSION_KEY => $branch->id]);

    try {
        $result = match ($action) {
            'confirm' => app(ConfirmPamamalengke::class)->execute($user, OperationPlan::query()->findOrFail($argv[8]), [
                'idempotency_key' => $key, 'payment_source' => 'cash',
                'items' => [['type' => 'ingredient', 'ingredient_id' => $argv[9], 'actual_quantity' => '2', 'actual_unit_cost' => '10.00']],
            ])->id,
            'sale' => app(PayNowOrder::class)->execute($user, $branch, [
                'order_type' => 'take_out', 'customer_label' => 'Race', 'payment_method' => 'cash', 'cash_received' => '500.00',
                'cashless_amount' => null, 'idempotency_key' => $key,
                'items' => [['product_id' => $argv[8], 'quantity' => 1, 'notes' => null, 'modifiers' => [['group_id' => $argv[9], 'option_id' => $argv[10]]]]],
            ])->id,
            'wastage' => app(AdjustIngredientStock::class)->execute($user, Ingredient::query()->findOrFail($argv[8]), [
                'mode' => 'wastage', 'quantity' => '0.5', 'reason' => 'Spoiled', 'idempotency_key' => $key,
            ])->id,
        };
        echo json_encode(['status' => 200, 'id' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (HttpExceptionInterface $exception) {
        echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (ValidationException $exception) {
        echo json_encode(['status' => 422, 'errors' => $exception->errors()], JSON_THROW_ON_ERROR).PHP_EOL;
    }

    exit(0);
}

$processes = [];
$createdSchema = false;

try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verifyPhase16E(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verifyPhase16E(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');

    /** N. Column types, partial unique indexes and query indexes. */
    $numeric = fn (string $table, string $column): ?object => DB::selectOne(
        'SELECT data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = ? AND table_name = ? AND column_name = ?',
        [$schema, $table, $column],
    );
    foreach ([['branch_ingredient_stocks', 'on_hand'], ['ingredient_movements', 'quantity_delta'], ['ingredient_movements', 'balance_after'], ['recipe_lines', 'quantity'], ['order_recipe_snapshot_lines', 'quantity_per_unit']] as [$table, $column]) {
        $type = $numeric($table, $column);
        verifyPhase16E($type?->data_type === 'numeric' && (int) $type->numeric_precision === 18 && (int) $type->numeric_scale === 4, "{$table}.{$column} must be numeric(18,4).");
    }
    verifyPhase16E($numeric('ingredients', 'purchase_unit_cost')?->numeric_scale === 2, 'Purchase cost must be numeric(14,2).');
    $indexes = collect(DB::select('SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = ?', [$schema]))->keyBy('indexname');
    foreach (['sale_once' => 'sale_consumption', 'void_once' => 'void_restoration'] as $name => $type) {
        $definition = $indexes['ingredient_movements_'.$name]->indexdef ?? '';
        verifyPhase16E(str_starts_with($definition, 'CREATE UNIQUE INDEX') && preg_match("/WHERE .*movement_type.*'{$type}'/", $definition) === 1, "Partial unique index {$name} is missing.");
    }
    foreach (['branch_ingredient_stocks_branch_id_ingredient_id_unique', 'operation_plan_products_product_id_unique', 'ingredient_movements_branch_id_ingredient_id_created_at_index', 'pamamalengke_purchases_store_session_expense_id_unique', 'pamamalengke_purchases_idempotency_key_unique', 'order_recipe_snapshots_order_id_product_id_size_key_unique'] as $index) {
        verifyPhase16E($indexes->has($index), "Index {$index} is missing.");
    }
    verifyPhase16E(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0, 'Phase 16E rollback failed.');
    verifyPhase16E(! DB::getSchemaBuilder()->hasTable('ingredient_movements') && ! DB::getSchemaBuilder()->hasColumn('products', 'no_recipe_needed'), 'Phase 16E rollback left tables behind.');
    verifyPhase16E(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 16E reapply failed.');
    echo 'MIGRATION PASS: numeric(18,4) quantities, partial unique indexes, rollback and reapply.'.PHP_EOL;

    $ops = OperationsScenario::create();
    $lemonId = $ops->ingredients['lemon']->id;

    /** A. Exact fractional stock. */
    verifyPhase16E(BranchIngredientStock::query()->where('ingredient_id', $lemonId)->value('on_hand') === '29.5000', 'Opening 29.5 was not stored exactly.');
    echo 'A PASS: 29.5 stored exactly as numeric 29.5000.'.PHP_EOL;

    /** B / C. Pay Now and Pay Later consume the recipe exactly once. */
    $payNow = $ops->payNow([$ops->line($ops->lemonYakult, 1, 'm')]);
    verifyPhase16E($ops->stock('lemon') === '29' && $ops->stock('syrup') === '970', 'Pay Now deduction is not exact.');
    $payLater = $ops->payLater([$ops->line($ops->lemonYakult, 2, 'm')]);
    verifyPhase16E($ops->stock('lemon') === '28' && $ops->stock('yakult') === '7', 'Pay Later deduction is not exact.');
    $movementsBeforeSettle = IngredientMovement::query()->count();
    $ops->settle($payLater);
    verifyPhase16E(IngredientMovement::query()->count() === $movementsBeforeSettle, 'Settlement consumed ingredients again.');
    echo 'B/C PASS: Pay Now and Pay Later consume once; settlement moves nothing.'.PHP_EOL;

    /** D. Edit delta only. */
    $editable = $ops->payLater([$ops->line($ops->lemonYakult, 2, 'm')]);
    $ops->edit($editable, [$ops->line($ops->lemonYakult, 1, 'm')]);
    $delta = IngredientMovement::query()->where('order_id', $editable->id)->where('movement_type', 'order_edit_adjustment')->where('ingredient_id', $lemonId)->sole();
    verifyPhase16E($delta->quantity_delta === '0.5000' && $ops->stock('lemon') === '27.5', 'Edit did not append only the +0.5 delta.');
    echo 'D PASS: edit appended only the compensating delta.'.PHP_EOL;

    /** E / F. Recipe change then Void restores the historical quantities exactly once. */
    $ops->recipe($ops->lemonYakult, 'm', ['lemon' => '1', 'yakult' => '2']);
    $voidKey = (string) Str::uuid();
    $version = $payNow->fresh()->version;
    $ops->void($payNow, $voidKey, $version);
    $ops->void($payNow, $voidKey, $version);
    $restored = IngredientMovement::query()->where('order_id', $payNow->id)->where('movement_type', 'void_restoration')->where('ingredient_id', $lemonId)->sole();
    verifyPhase16E($restored->quantity_delta === '0.5000', 'Void did not use the historical recipe.');
    verifyPhase16E(IngredientMovement::query()->where('order_id', $payNow->id)->where('movement_type', 'void_restoration')->count() === 4, 'Void restored more than once.');
    try {
        IngredientMovement::query()->create([
            'branch_id' => $ops->branch->id, 'ingredient_id' => $lemonId, 'movement_type' => 'void_restoration', 'quantity_delta' => '1.0000',
            'balance_after' => '1.0000', 'order_id' => $payNow->id, 'order_recipe_snapshot_id' => $restored->order_recipe_snapshot_id,
        ]);
        verifyPhase16E(false, 'PostgreSQL accepted a second void restoration row.');
    } catch (UniqueConstraintViolationException) {
        DB::rollBack();
    }
    echo 'E/F PASS: void restores the historical recipe once; the database rejects a second restoration.'.PHP_EOL;

    /** G. Negative balance is allowed for sales. */
    $ops->payNow([$ops->line($ops->lemonYakult, 30, 'l')]);
    verifyPhase16E(str_starts_with($ops->stock('lemon'), '-'), 'Negative ingredient balance was blocked.');
    echo 'G PASS: shortage never blocks a sale; balance is '.$ops->stock('lemon').'.'.PHP_EOL;

    /** H / I / K / M. Purchase conversion, shared stock, one expense and stable cost snapshots. */
    $cogsBefore = app(OperationsSummary::class)->today($ops->branch)['business']['cogs_cents'];
    session([ActiveBranchContext::SESSION_KEY => $ops->branch->id]);
    $purchase = app(ConfirmPamamalengke::class)->execute($ops->owner, $ops->silog, [
        'idempotency_key' => (string) Str::uuid(), 'payment_source' => 'cashless',
        'items' => [
            ['type' => 'ingredient', 'ingredient_id' => $ops->ingredients['water']->id, 'actual_quantity' => '0.5', 'actual_unit_cost' => '44.00'],
            ['type' => 'manual', 'name' => 'Dishwashing liquid', 'unit' => 'pouch', 'actual_quantity' => '1', 'actual_unit_cost' => '89.00'],
        ],
    ]);
    verifyPhase16E(StoreSessionExpense::query()->whereKey($purchase->store_session_expense_id)->value('amount') === '111.00', 'Expense total is wrong.');
    verifyPhase16E(IngredientMovement::query()->where('pamamalengke_purchase_id', $purchase->id)->sole()->quantity_delta === '2500.0000', '0.5 gallon did not restock exactly 2500 ml.');
    verifyPhase16E(BranchIngredientStock::query()->where('ingredient_id', $ops->ingredients['water']->id)->count() === 1, 'Shared ingredient has more than one balance.');
    verifyPhase16E(app(OperationsSummary::class)->today($ops->branch)['business']['cogs_cents'] === $cogsBefore, 'A new purchase cost rewrote historical COGS.');
    echo 'H/I/K/M PASS: exact unit conversion, one shared balance, one expense, stable cost snapshots.'.PHP_EOL;

    /** J. Cross-branch isolation. */
    $east = Branch::factory()->create(['code' => 'EAST']);
    verifyPhase16E(BranchIngredientStock::query()->where('branch_id', $east->id)->doesntExist(), 'Another branch received stock.');
    echo 'J PASS: other branches have no ingredient balance until their own movements.'.PHP_EOL;

    /** L. Concurrent duplicate confirmations and interleaved sale + wastage on one ingredient. */
    $raceKey = strtolower((string) Str::uuid());
    $start = function (string $label, array $workerArgs, string $lockSql, array $lockBindings) use ($schema, $connection, &$processes): array {
        DB::beginTransaction();
        DB::select($lockSql, $lockBindings);
        $workers = [];
        foreach ($workerArgs as $index => $args) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema, $schema.'_'.$label.'_'.$index, ...$args], dirname(__DIR__), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
                'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
            ], timeout: 40);
            $processes[] = $process;
            $workers[] = $process;
            $process->start();
        }
        awaitPhase16ELocks(DB::connection('phase16e_admin'), $schema.'_'.$label.'_', $workers);
        DB::commit();

        return array_map(function (Process $process): array {
            verifyPhase16E($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    };
    $before = ExactQuantity::parse(BranchIngredientStock::query()->where('ingredient_id', $lemonId)->value('on_hand'));
    $results = $start('confirm', [
        ['confirm', (string) $ops->owner->id, $ops->branch->id, $raceKey, $ops->drinks->id, $lemonId],
        ['confirm', (string) $ops->owner->id, $ops->branch->id, $raceKey, $ops->drinks->id, $lemonId],
    ], 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['pamamalengke:'.$raceKey]);
    verifyPhase16E(array_column($results, 'status') === [200, 200] && count(array_unique(array_column($results, 'id'))) === 1, 'Duplicate confirmation did not replay: '.json_encode($results));
    verifyPhase16E(PamamalengkePurchase::query()->where('idempotency_key', $raceKey)->count() === 1 && StoreSessionExpense::query()->where('idempotency_key', $raceKey)->count() === 1, 'Duplicate confirmation wrote twice.');
    $afterConfirm = ExactQuantity::parse(BranchIngredientStock::query()->where('ingredient_id', $lemonId)->value('on_hand'));
    verifyPhase16E($afterConfirm - $before === 2 * ExactQuantity::FACTOR, 'Duplicate confirmation restocked twice.');

    /** Count back to 10 so both writers succeed; the Medium recipe now uses 1 Lemon (changed in E). */
    app(AdjustIngredientStock::class)->execute($ops->owner, $ops->ingredients['lemon'], [
        'mode' => 'count', 'quantity' => '10', 'reason' => 'End-of-day count', 'idempotency_key' => (string) Str::uuid(),
    ]);
    $afterConfirm = ExactQuantity::parse(BranchIngredientStock::query()->where('ingredient_id', $lemonId)->value('on_hand'));
    $results = $start('mixed', [
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $ops->sizeGroup->id, $ops->sizes['m']->id],
        ['wastage', (string) $ops->owner->id, $ops->branch->id, (string) Str::uuid(), $lemonId],
    ], 'SELECT id FROM branch_ingredient_stocks WHERE branch_id = ? AND ingredient_id = ? FOR UPDATE', [$ops->branch->id, $lemonId]);
    $afterMixed = ExactQuantity::parse(BranchIngredientStock::query()->where('ingredient_id', $lemonId)->value('on_hand'));
    verifyPhase16E(array_column($results, 'status') === [200, 200] && $afterMixed === $afterConfirm - 10_000 - 5_000, 'Concurrent sale and wastage lost an update: '.json_encode($results));
    $ledger = ExactQuantity::parse((string) DB::selectOne('SELECT SUM(quantity_delta) AS total FROM ingredient_movements WHERE branch_id = ? AND ingredient_id = ?', [$ops->branch->id, $lemonId])->total);
    verifyPhase16E($ledger === $afterMixed, 'Ledger sum and balance disagree.');
    verifyPhase16E(OrderRecipeSnapshot::query()->count() > 0, 'No recipe snapshots were written.');
    echo 'L PASS: duplicate confirmation replays once; concurrent sale and wastage keep the balance equal to the ledger.'.PHP_EOL;
    /** P. Every Operations page projection runs on PostgreSQL, for one Branch and for All Branches. */
    $workspace = app(OperationsWorkspace::class);
    foreach ([$ops->branch, null] as $scope) {
        $plans = $workspace->activePlans();
        $workspace->context('plans', $scope, $plans, $ops->drinks);
        $workspace->plansPage($scope, $plans);
        $workspace->overviewPage($scope, $ops->drinks);
        $workspace->ingredientsPage($scope);
        $workspace->recipesPage($scope, $ops->drinks);
        $workspace->pamamalengkePage($scope, $ops->drinks);
        $workspace->purchasesPage($scope, $ops->drinks, 1);
        $workspace->purchasesPage($scope, null, 1);
        if ($scope !== null) {
            $workspace->stockPage($scope, $ops->drinks);
        }
    }
    echo 'P PASS: every Operations page projection runs on PostgreSQL (Branch and All Branches).'.PHP_EOL;
    echo 'PASS: PostgreSQL Phase 16E Operations invariants.'.PHP_EOL;
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
        verifyPhase16E($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS: removed isolated schema '.$schema.PHP_EOL;
    }
}
