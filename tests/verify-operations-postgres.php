<?php

/**
 * Opt-in real PostgreSQL verification: php tests/verify-operations-postgres.php
 *
 * Creates and removes only a random phase16e_* schema on a loopback PostgreSQL server. The configured public schema
 * (the normal local development data) is never migrated, truncated, or written.
 */

use App\Actions\Inventory\AdjustInventory;
use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Operations\ConfirmPamamalengke;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Actions\Orders\VoidOrder;
use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Actions\StoreSessions\RecordStoreSessionGiveaway;
use App\Actions\StoreSessions\RecordStoreSessionInventoryAdjustment;
use App\Actions\StoreSessions\ReverseStoreSessionGiveaway;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchInventory;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\KitchenTicket;
use App\Models\OperationPlan;
use App\Models\Order;
use App\Models\OrderRecipeSnapshot;
use App\Models\PamamalengkePurchase;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreSessionExpense;
use App\Models\StoreSessionGiveaway;
use App\Models\StoreSessionGiveawayReversal;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\ExactQuantity;
use App\Support\OperationsSummary;
use App\Support\OperationsWorkspace;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
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
                'items' => [['product_id' => $argv[8], 'quantity' => 1, 'notes' => null, 'modifiers' => json_decode($argv[9], true, flags: JSON_THROW_ON_ERROR)]],
            ])->id,
            'commit_later' => app(CommitPayLaterOrder::class)->execute($user, $branch, Order::query()->findOrFail($argv[8]), ['idempotency_key' => $key])->id,
            'edit' => app(EditCommittedOrder::class)->execute($user, $branch, Order::query()->findOrFail($argv[8]), [
                'idempotency_key' => $key, 'expected_version' => (int) $argv[10], 'order_type' => 'take_out', 'customer_label' => 'Race',
                'branch_table_id' => null, 'reason' => 'Race edit', 'refund_cash_amount' => null,
                'items' => json_decode($argv[9], true, flags: JSON_THROW_ON_ERROR),
            ])->id,
            'wastage' => app(AdjustIngredientStock::class)->execute($user, Ingredient::query()->findOrFail($argv[8]), [
                'mode' => 'wastage', 'quantity' => '0.5', 'reason' => 'Spoiled', 'idempotency_key' => $key,
            ])->id,
            'adjust_product' => app(AdjustInventory::class)->execute($user, $branch, Product::query()->findOrFail($argv[8]), -1, 'Recount')->id,
            'store_expense' => app(RecordStoreSessionExpense::class)->execute($user, $branch, [
                'idempotency_key' => $key, 'description' => 'Race purchase', 'amount' => '25.00', 'payment_source' => 'cash', 'note' => null,
                ...($argv[8] === 'none' ? ['restock' => false] : ['restock' => true, 'product_id' => $argv[8], 'quantity' => 2]),
            ])->id,
            'store_adjust' => app(RecordStoreSessionInventoryAdjustment::class)->execute($user, $branch, [
                'idempotency_key' => $key, 'reason_code' => 'damaged', 'product_id' => $argv[8], 'quantity' => 1, 'note' => null,
            ])->id,
            'settle' => app(SettlePayLaterOrder::class)->execute($user, $branch, Order::query()->findOrFail($argv[8]), [
                'idempotency_key' => $key, 'payment_method' => 'cash', 'cash_received' => '9999.00', 'cashless_amount' => null,
            ])->id,
            'void' => app(VoidOrder::class)->execute($user, $branch, Order::query()->findOrFail($argv[8]), [
                'reason_code' => 'wrong_item', 'reason_text' => null, 'authorization_pin' => '1234',
                'idempotency_key' => $key, 'expected_version' => (int) $argv[9],
            ])->id,
            'giveaway' => app(RecordStoreSessionGiveaway::class)->execute($user, $branch, [
                'idempotency_key' => $key, 'product_id' => $argv[8], 'quantity' => 1, 'reason_code' => 'complimentary', 'note' => null,
                'modifiers' => json_decode($argv[9], true, flags: JSON_THROW_ON_ERROR),
            ])->id,
            'reverse_giveaway' => app(ReverseStoreSessionGiveaway::class)->execute($user, $branch, StoreSessionGiveaway::query()->findOrFail($argv[8]), [
                'idempotency_key' => $key, 'reason' => 'Recorded by mistake',
            ])->id,
        };
        echo json_encode(['status' => 200, 'id' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (HttpExceptionInterface $exception) {
        echo json_encode(['status' => $exception->getStatusCode()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (ValidationException $exception) {
        echo json_encode(['status' => 422, 'errors' => $exception->errors()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (QueryException $exception) {
        echo json_encode(['status' => 500, 'sqlstate' => $exception->errorInfo[0] ?? null, 'message' => mb_substr($exception->getMessage(), 0, 200)], JSON_THROW_ON_ERROR).PHP_EOL;
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
    foreach ([['branch_ingredient_stocks', 'on_hand'], ['ingredient_movements', 'quantity_delta'], ['ingredient_movements', 'balance_after'], ['recipe_lines', 'quantity'], ['order_recipe_snapshot_lines', 'quantity_per_unit'], ['product_modifier_effect_lines', 'quantity'], ['order_recipe_snapshot_modifier_lines', 'quantity_per_selection']] as [$table, $column]) {
        $type = $numeric($table, $column);
        verifyPhase16E($type?->data_type === 'numeric' && (int) $type->numeric_precision === 18 && (int) $type->numeric_scale === 4, "{$table}.{$column} must be numeric(18,4).");
    }
    verifyPhase16E($numeric('ingredients', 'purchase_unit_cost')?->numeric_scale === 2, 'Purchase cost must be numeric(14,2).');
    $indexes = collect(DB::select('SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = ?', [$schema]))->keyBy('indexname');
    foreach (['sale_once' => 'sale_consumption', 'void_once' => 'void_restoration'] as $name => $type) {
        $definition = $indexes['ingredient_movements_'.$name]->indexdef ?? '';
        verifyPhase16E(str_starts_with($definition, 'CREATE UNIQUE INDEX') && preg_match("/WHERE .*movement_type.*'{$type}'/", $definition) === 1, "Partial unique index {$name} is missing.");
    }
    foreach (['branch_ingredient_stocks_branch_id_ingredient_id_unique', 'operation_plan_products_product_id_unique', 'ingredient_movements_branch_id_ingredient_id_created_at_index', 'pamamalengke_purchases_store_session_expense_id_unique', 'pamamalengke_purchases_idempotency_key_unique', 'order_recipe_snapshots_order_id_product_id_size_key_unique', 'product_modifier_effects_product_id_modifier_option_id_unique', 'product_modifier_effect_lines_unique', 'order_recipe_snapshot_modifiers_unique', 'order_recipe_snapshot_modifier_lines_unique'] as $index) {
        verifyPhase16E($indexes->has($index), "Index {$index} is missing.");
    }
    foreach (['giveaway' => 'giveaway', 'giveaway_reversal_once' => 'giveaway_reversal'] as $name => $type) {
        $definition = $indexes['ingredient_movements_'.($name === 'giveaway' ? 'giveaway_once' : $name)]->indexdef ?? '';
        verifyPhase16E(str_starts_with($definition, 'CREATE UNIQUE INDEX') && preg_match("/WHERE .*movement_type.*'{$type}'/", $definition) === 1, "Partial unique index {$name} is missing.");
    }
    foreach (['store_session_giveaways_idempotency_key_unique', 'store_session_giveaways_inventory_movement_id_unique', 'store_session_giveaway_reversals_giveaway_id_unique', 'store_session_giveaway_reversals_idempotency_key_unique', 'ingredient_movements_store_session_giveaway_id_index'] as $index) {
        verifyPhase16E($indexes->has($index), "Index {$index} is missing.");
    }
    /**
     * PostgreSQL silently truncates Laravel-generated names at 63 bytes. These known ones are truncated deterministically
     * without collision (creation would fail otherwise); any new truncated index or key name fails this check.
     */
    $knownTruncated = [
        'operation_plan_ingredients_operation_plan_id_ingredient_id_uniq', 'order_recipe_snapshot_lines_order_recipe_snapshot_id_ingredient',
        'pamamalengke_list_entries_branch_id_operation_plan_id_entry_typ', 'store_session_inventory_adjustments_inventory_movement_id_forei',
        'store_session_inventory_adjustments_inventory_movement_id_uniqu', 'store_session_inventory_adjustments_store_session_id_created_at',
    ];
    $long = array_values(array_diff(array_column(DB::select("SELECT conname AS name FROM pg_constraint WHERE connamespace = ?::regnamespace AND contype IN ('p', 'u', 'f') AND length(conname) >= 63
        UNION SELECT indexname FROM pg_indexes WHERE schemaname = ? AND length(indexname) >= 63", [$schema, $schema]), 'name'), $knownTruncated));
    verifyPhase16E($long === [], 'New identifier truncated at the PostgreSQL length limit: '.json_encode($long));
    foreach (['ingredient_movements', 'inventory_movements'] as $table) {
        $check = DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%movement_type%'", [$table])->def ?? '';
        verifyPhase16E(str_contains($check, "'giveaway'") && str_contains($check, "'giveaway_reversal'"), "{$table}.movement_type does not accept giveaways.");
    }
    verifyPhase16E(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0, 'Giveaway rollback failed.');
    verifyPhase16E(! DB::getSchemaBuilder()->hasTable('store_session_giveaways') && ! DB::getSchemaBuilder()->hasColumn('ingredient_movements', 'store_session_giveaway_id')
        && ! str_contains(DB::selectOne("SELECT pg_get_constraintdef(oid) AS def FROM pg_constraint WHERE conrelid = 'inventory_movements'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE '%movement_type%'")->def, 'giveaway')
        && DB::getSchemaBuilder()->hasTable('product_modifier_effects'), 'Giveaway rollback was not isolated.');
    verifyPhase16E(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0, 'Add-on effects rollback failed.');
    verifyPhase16E(! DB::getSchemaBuilder()->hasTable('product_modifier_effects') && ! DB::getSchemaBuilder()->hasTable('order_recipe_snapshot_modifier_lines') && DB::getSchemaBuilder()->hasTable('ingredient_movements'), 'Add-on effects rollback was not isolated.');
    verifyPhase16E(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0, 'Phase 16E rollback failed.');
    verifyPhase16E(! DB::getSchemaBuilder()->hasTable('ingredient_movements') && ! DB::getSchemaBuilder()->hasColumn('products', 'no_recipe_needed'), 'Phase 16E rollback left tables behind.');
    verifyPhase16E(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 16E reapply failed.');
    echo 'MIGRATION PASS: numeric(18,4) quantities, partial unique indexes, giveaway movement types, no new truncated identifiers, rollback and reapply.'.PHP_EOL;

    $ops = OperationsScenario::create()->withAddOns();
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

    /** G. A sale can never drive a recipe Ingredient below zero (supersedes the earlier negative-stock sales). */
    $lemonBefore = $ops->stock('lemon');
    try {
        $ops->payNow([$ops->line($ops->lemonYakult, 30, 'l')]);
        verifyPhase16E(false, 'An oversized sale was accepted.');
    } catch (ValidationException) {
        verifyPhase16E($ops->stock('lemon') === $lemonBefore && ! str_starts_with($lemonBefore, '-'), 'A rejected sale moved stock.');
    }
    echo 'G PASS: a sale beyond Recipe stock is rejected; lemon stays '.$ops->stock('lemon').'.'.PHP_EOL;

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
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, json_encode($ops->line($ops->lemonYakult, 1, 'm')['modifiers'], JSON_THROW_ON_ERROR)],
        ['wastage', (string) $ops->owner->id, $ops->branch->id, (string) Str::uuid(), $lemonId],
    ], 'SELECT id FROM branch_ingredient_stocks WHERE branch_id = ? AND ingredient_id = ? FOR UPDATE', [$ops->branch->id, $lemonId]);
    $afterMixed = ExactQuantity::parse(BranchIngredientStock::query()->where('ingredient_id', $lemonId)->value('on_hand'));
    verifyPhase16E(array_column($results, 'status') === [200, 200] && $afterMixed === $afterConfirm - 10_000 - 5_000, 'Concurrent sale and wastage lost an update: '.json_encode($results));
    $ledger = ExactQuantity::parse((string) DB::selectOne('SELECT SUM(quantity_delta) AS total FROM ingredient_movements WHERE branch_id = ? AND ingredient_id = ?', [$ops->branch->id, $lemonId])->total);
    verifyPhase16E($ledger === $afterMixed, 'Ledger sum and balance disagree.');
    verifyPhase16E(OrderRecipeSnapshot::query()->count() > 0, 'No recipe snapshots were written.');
    echo 'L PASS: duplicate confirmation replays once; concurrent sale and wastage keep the balance equal to the ledger.'.PHP_EOL;

    /**
     * R. Recipe availability under real concurrency. Each race holds the contested Ingredient balance row while both
     * workers queue, then releases it: exactly one wins, the loser gets a clean 422 with no Payment, committed Order,
     * Kitchen ticket or Ingredient movement, stock never goes below zero and the ledger still equals the balance.
     */
    $deadlocks = function (): int {
        DB::connection('phase16e_admin')->select('SELECT pg_stat_clear_snapshot()');

        return (int) DB::connection('phase16e_admin')->selectOne('SELECT deadlocks FROM pg_stat_database WHERE datname = current_database()')->deadlocks;
    };
    $deadlocksBefore = $deadlocks();
    /** E changed Medium to lemon 1 + yakult 2; the races use the standard Medium recipe again (future sales only). */
    $ops->recipe($ops->lemonYakult, 'm', ['lemon' => '0.5', 'yakult' => '1', 'syrup' => '30', 'water' => '250']);
    $effects = fn (): array => [
        'payments' => Payment::query()->count(),
        'committed' => Order::query()->whereNotNull('committed_at')->count(),
        'tickets' => KitchenTicket::query()->count(),
    ];
    $stockRow = 'SELECT id FROM branch_ingredient_stocks WHERE branch_id = ? AND ingredient_id = ? FOR UPDATE';
    $modifiers = fn (?string $size, array $options = []): string => json_encode($ops->line($ops->lemonYakult, 1, $size, $options)['modifiers'], JSON_THROW_ON_ERROR);
    $oneWinner = function (array $results, string $scenario) {
        $statuses = array_column($results, 'status');
        sort($statuses);
        verifyPhase16E($statuses === [200, 422], "{$scenario}: expected exactly one winner, got ".json_encode($results));
    };
    $ledgerMatches = function (string $ingredient) use ($ops): void {
        $id = $ops->ingredients[$ingredient]->id;
        $ledger = ExactQuantity::parse((string) DB::selectOne('SELECT SUM(quantity_delta) AS total FROM ingredient_movements WHERE branch_id = ? AND ingredient_id = ?', [$ops->branch->id, $id])->total);
        verifyPhase16E($ledger === ExactQuantity::parse(BranchIngredientStock::query()->where('branch_id', $ops->branch->id)->where('ingredient_id', $id)->value('on_hand')), "{$ingredient} ledger and balance disagree.");
    };

    /** R-A. Only enough Yakult for one Medium; two simultaneous Pay Now commits. */
    $ops->setStock('yakult', '1');
    $before = $effects();
    $results = $start('ra', [
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
    ], $stockRow, [$ops->branch->id, $ops->ingredients['yakult']->id]);
    $oneWinner($results, 'R-A');
    verifyPhase16E($ops->stock('yakult') === '0', 'R-A: Yakult is not exactly 0.');
    verifyPhase16E($effects() === ['payments' => $before['payments'] + 1, 'committed' => $before['committed'] + 1, 'tickets' => $before['tickets'] + 1], 'R-A: the losing sale left partial effects.');
    $ledgerMatches('yakult');
    echo 'R-A PASS: two Pay Now commits for the last Medium: one wins, one gets 422, Yakult ends at 0.'.PHP_EOL;

    /** R-B. Lemon Yakult (250 ml) and Tapsilog (100 ml) share Water; 300 ml cannot make both. */
    $ops->setStock('yakult', '10');
    $ops->setStock('water', '300');
    $before = $effects();
    $results = $start('rb', [
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->tapsilog->id, '[]'],
    ], $stockRow, [$ops->branch->id, $ops->ingredients['water']->id]);
    $oneWinner($results, 'R-B');
    verifyPhase16E(in_array($ops->stock('water'), ['50', '200'], true), 'R-B: shared Water oversold: '.$ops->stock('water'));
    verifyPhase16E($effects()['payments'] === $before['payments'] + 1, 'R-B: the losing sale left a Payment.');
    $ledgerMatches('water');
    echo 'R-B PASS: two different Products racing for shared Water never oversell (Water '.$ops->stock('water').' ml).'.PHP_EOL;

    /** R-C. Medium + Extra Yakult needs the final 2 Yakult; two saved drafts race through the locked Pay Later commit. */
    $ops->setStock('water', '8000');
    $ops->setStock('yakult', '2');
    $drafts = array_map(fn (): Order => app(CreatePosDraftOrder::class)->execute($ops->cashier, $ops->branch, [
        'order_type' => 'take_out', 'customer_label' => 'Race draft', 'items' => [$ops->line($ops->lemonYakult, 1, 'm', ['extra_yakult'])],
    ]), [1, 2]);
    $before = $effects();
    $results = $start('rc', array_map(fn (Order $draft): array => ['commit_later', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $draft->id], $drafts),
        $stockRow, [$ops->branch->id, $ops->ingredients['yakult']->id]);
    $oneWinner($results, 'R-C');
    verifyPhase16E($ops->stock('yakult') === '0', 'R-C: Yakult is not exactly 0.');
    verifyPhase16E($effects()['committed'] === $before['committed'] + 1 && $effects()['tickets'] === $before['tickets'] + 1, 'R-C: the losing commit left partial effects.');
    verifyPhase16E(Order::query()->whereKey(array_map(fn (Order $draft): string => $draft->id, $drafts))->whereNull('committed_at')->count() === 1, 'R-C: the losing draft did not stay a draft.');
    $ledgerMatches('yakult');
    echo 'R-C PASS: Size + Add-on drafts racing for the final 2 Yakult: the locked commit lets exactly one through.'.PHP_EOL;

    /** R-D. An edit that needs one more Yakult races a new sale for the last Yakult. */
    $ops->setStock('yakult', '2');
    $edited = $ops->payLater([$ops->line($ops->lemonYakult, 1, 'm')]);
    verifyPhase16E($ops->stock('yakult') === '1', 'R-D setup failed.');
    $version = $edited->fresh()->version;
    $before = $effects();
    $results = $start('rd', [
        ['edit', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $edited->id, json_encode([$ops->line($ops->lemonYakult, 2, 'm')], JSON_THROW_ON_ERROR), (string) $version],
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
    ], $stockRow, [$ops->branch->id, $ops->ingredients['yakult']->id]);
    $oneWinner($results, 'R-D');
    verifyPhase16E($ops->stock('yakult') === '0', 'R-D: Yakult is not exactly 0: '.$ops->stock('yakult'));
    $editWon = $results[0]['status'] === 200;
    verifyPhase16E($edited->fresh()->version === ($editWon ? $version + 1 : $version), 'R-D: the edit left a partial version change.');
    verifyPhase16E($effects()['payments'] === $before['payments'] + ($editWon ? 0 : 1), 'R-D: the losing sale left a Payment.');
    $ledgerMatches('yakult');
    echo 'R-D PASS: a usage-increasing edit racing a sale for the last Yakult: '.($editWon ? 'edit' : 'sale').' won, no negative stock or partial state.'.PHP_EOL;

    /** R-F. A Void restoring Yakult races a sale needing Yakult: both commit in either order and the ledger holds. */
    $ops->setStock('yakult', '2');
    $voided = $ops->payNow([$ops->line($ops->lemonYakult, 1, 'm')]);
    $results = $start('rf', [
        ['void', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $voided->id, (string) $voided->fresh()->version],
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
    ], $stockRow, [$ops->branch->id, $ops->ingredients['yakult']->id]);
    verifyPhase16E(array_column($results, 'status') === [200, 200] && $ops->stock('yakult') === '1', 'R-F: void vs sale lost an update: '.json_encode($results).' yakult '.$ops->stock('yakult'));
    $ledgerMatches('yakult');
    echo 'R-F PASS: a Void restoring Yakult and a sale consuming it both commit; Yakult 1, ledger = balance.'.PHP_EOL;

    /** R-G. A pamamalengke restock of Lemon races a sale using Lemon: both commit and the restock is never lost. */
    $ops->setStock('lemon', '0.5');
    $results = $start('rg', [
        ['confirm', (string) $ops->owner->id, $ops->branch->id, (string) Str::uuid(), $ops->drinks->id, $lemonId],
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
    ], $stockRow, [$ops->branch->id, $lemonId]);
    verifyPhase16E(array_column($results, 'status') === [200, 200] && $ops->stock('lemon') === '2', 'R-G: restock vs sale lost an update: '.json_encode($results).' lemon '.$ops->stock('lemon'));
    $ledgerMatches('lemon');
    echo 'R-G PASS: a pamamalengke restock and a sale on the same Lemon both commit; Lemon 0.5 + 2 − 0.5 = 2.'.PHP_EOL;

    /** R-E. None of these races deadlocked (Branch → Session → Order → Product stock → Ingredient balances). */
    usleep(300_000);
    verifyPhase16E($deadlocks() === $deadlocksBefore, 'R-E: PostgreSQL recorded a deadlock during the Recipe races.');
    echo 'R-E PASS: no deadlocks during the Recipe availability races.'.PHP_EOL;

    /**
     * S. Direct Product stock (Coke) writers racing a Pay Now sale. Each writer is queued FIRST behind the held row,
     * then the sale, so the writer runs first after release while the sale already holds the Branch FOR UPDATE: the
     * exact interleaving that deadlocks unless every writer takes the Branch (FOR SHARE) before its other locks.
     */
    $startInOrder = function (string $label, array $workerArgs, string $lockSql, array $lockBindings) use ($schema, $connection, &$processes): array {
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
            awaitPhase16ELocks(DB::connection('phase16e_admin'), $schema.'_'.$label.'_', $workers);
        }
        DB::commit();

        return array_map(function (Process $process): array {
            verifyPhase16E($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    };
    $cokeSale = fn (): array => ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->coke->id, '[]'];
    $cokeLedgerMatches = function (string $scenario) use ($ops): void {
        $balance = BranchInventory::query()->where('branch_id', $ops->branch->id)->where('product_id', $ops->coke->id)->value('on_hand');
        $ledger = (int) DB::selectOne('SELECT COALESCE(SUM(quantity_delta), 0) AS total FROM inventory_movements WHERE branch_id = ? AND product_id = ?', [$ops->branch->id, $ops->coke->id])->total;
        verifyPhase16E($balance >= 0, "{$scenario}: Coke went negative.");
        verifyPhase16E($ledger === $balance - 20, "{$scenario}: Coke ledger and balance disagree ({$ledger} vs {$balance}).");
    };
    $sessionRow = 'SELECT id FROM store_sessions WHERE id = ? FOR UPDATE';
    $productRow = 'SELECT id FROM branch_products WHERE branch_id = ? AND product_id = ? FOR UPDATE';
    $cokeLater = $ops->payLater([$ops->line($ops->coke, 1)]);
    $cokePaid = $ops->payNow([$ops->line($ops->coke, 1)]);
    $deadlocksBefore = $deadlocks();
    $failures = [];
    foreach ([
        'sa' => ['manual Product inventory adjustment', ['adjust_product', (string) $ops->owner->id, $ops->branch->id, (string) Str::uuid(), $ops->coke->id], $productRow, [$ops->branch->id, $ops->coke->id]],
        'sb' => ['Store Purchase restock', ['store_expense', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->coke->id], $sessionRow, [$ops->session->id]],
        'sc' => ['Store Session inventory adjustment', ['store_adjust', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->coke->id], $sessionRow, [$ops->session->id]],
        'sd' => ['plain Store Expense', ['store_expense', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), 'none'], $sessionRow, [$ops->session->id]],
        'se' => ['Pay Later settlement', ['settle', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $cokeLater->id], $sessionRow, [$ops->session->id]],
        'sf' => ['Void of a Coke sale', ['void', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $cokePaid->id, (string) $cokePaid->fresh()->version], $sessionRow, [$ops->session->id]],
    ] as $label => [$name, $writer, $lockSql, $bindings]) {
        $results = $startInOrder($label, [$writer, $cokeSale()], $lockSql, $bindings);
        $cokeLedgerMatches(strtoupper($label));
        usleep(300_000);
        $recorded = $deadlocks() - $deadlocksBefore;
        $deadlocksBefore += $recorded;
        if (array_column($results, 'status') !== [200, 200] || $recorded !== 0) {
            $failures[] = strtoupper($label).": {$name} vs Pay Now: {$recorded} deadlock(s), results ".json_encode($results);
            echo strtoupper($label)." FAIL: {$name} vs Pay Now deadlocked.".PHP_EOL;

            continue;
        }
        echo strtoupper($label)." PASS: {$name} queued ahead of a Coke Pay Now: both commit, no deadlock, ledger = balance.".PHP_EOL;
    }
    verifyPhase16E($failures === [], implode(PHP_EOL, $failures));

    /**
     * G. Giveaways race sales and each other: a giveaway and a Pay Now for the last Medium Yakult (exactly one wins, no
     * negative stock), a direct-stock giveaway queued ahead of a Coke sale (no deadlock), a duplicate submit (one
     * record) and two different reversal requests (restored exactly once).
     */
    $deadlocksBefore = $deadlocks();
    $ops->setStock('yakult', '1');
    $giveawayCount = StoreSessionGiveaway::query()->count();
    $before = $effects();
    $results = $start('ga', [
        ['giveaway', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
        ['sale', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->lemonYakult->id, $modifiers('m')],
    ], $stockRow, [$ops->branch->id, $ops->ingredients['yakult']->id]);
    $oneWinner($results, 'G-A');
    $giveawayWon = $results[0]['status'] === 200;
    verifyPhase16E($ops->stock('yakult') === '0', 'G-A: Yakult is not exactly 0.');
    verifyPhase16E(StoreSessionGiveaway::query()->count() === $giveawayCount + ($giveawayWon ? 1 : 0), 'G-A: the losing giveaway left a record.');
    verifyPhase16E($effects()['payments'] === $before['payments'] + ($giveawayWon ? 0 : 1), 'G-A: a giveaway created a Payment or the losing sale left one.');
    $ledgerMatches('yakult');
    echo 'G-A PASS: a giveaway and a Pay Now race for the last Yakult: '.($giveawayWon ? 'giveaway' : 'sale').' won, the other got 422, Yakult 0.'.PHP_EOL;

    $results = $startInOrder('gb', [
        ['giveaway', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $ops->coke->id, '[]'],
        $cokeSale(),
    ], $sessionRow, [$ops->session->id]);
    verifyPhase16E(array_column($results, 'status') === [200, 200], 'G-B: Coke giveaway vs Pay Now did not both succeed: '.json_encode($results));
    $cokeLedgerMatches('G-B');
    echo 'G-B PASS: a Product-stock giveaway queued ahead of a Coke Pay Now: both commit, ledger = balance.'.PHP_EOL;

    $ops->setStock('yakult', '5');
    $giveawayKey = strtolower((string) Str::uuid());
    $results = $start('gc', [
        ['giveaway', (string) $ops->cashier->id, $ops->branch->id, $giveawayKey, $ops->lemonYakult->id, $modifiers('m')],
        ['giveaway', (string) $ops->cashier->id, $ops->branch->id, $giveawayKey, $ops->lemonYakult->id, $modifiers('m')],
    ], 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', [$ops->branch->id.':giveaway:'.$giveawayKey]);
    verifyPhase16E(array_column($results, 'status') === [200, 200] && count(array_unique(array_column($results, 'id'))) === 1, 'G-C: duplicate giveaway did not replay: '.json_encode($results));
    verifyPhase16E($ops->stock('yakult') === '4', 'G-C: a duplicate giveaway deducted twice: '.$ops->stock('yakult'));
    echo 'G-C PASS: a duplicate giveaway submit records once and deducts once.'.PHP_EOL;

    $giveawayId = $results[0]['id'];
    $results = $start('gd', [
        ['reverse_giveaway', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $giveawayId],
        ['reverse_giveaway', (string) $ops->cashier->id, $ops->branch->id, (string) Str::uuid(), $giveawayId],
    ], 'SELECT pg_advisory_xact_lock(hashtextextended(?, 0))', ['giveaway-reversal:'.$giveawayId]);
    $oneWinner($results, 'G-D');
    verifyPhase16E(StoreSessionGiveawayReversal::query()->where('giveaway_id', $giveawayId)->count() === 1 && $ops->stock('yakult') === '5', 'G-D: a giveaway was restored more than once.');
    $ledgerMatches('yakult');
    usleep(300_000);
    verifyPhase16E($deadlocks() === $deadlocksBefore, 'G: PostgreSQL recorded a deadlock during the giveaway races.');
    echo 'G-D PASS: two different reversal requests restore the giveaway exactly once; no deadlocks in G.'.PHP_EOL;

    /** P. Every Operations page projection runs on PostgreSQL, for one Branch and for All Branches. */
    $workspace = app(OperationsWorkspace::class);
    foreach ([$ops->branch, null] as $scope) {
        $plans = $workspace->activePlans();
        $workspace->context('plans', $scope, $plans, $ops->drinks);
        $workspace->plansPage($scope, $plans);
        $workspace->overviewPage($scope, $ops->drinks);
        $workspace->ingredientsPage($scope);
        $workspace->recipesPage($scope, $ops->drinks);
        app(BranchCatalog::class)->browse($ops->branch, true);
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
