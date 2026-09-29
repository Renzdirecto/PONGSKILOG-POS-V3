<?php

/**
 * Opt-in: DB_URL=null php tests/verify-branch-operations-postgres.php
 *
 * Phase 18 Manual QA pass #2.1 on real PostgreSQL, in one random bops_* schema that is dropped afterwards (the normal
 * development schema is never touched):
 *
 * A. cutover: the forward migration turns a legacy shared Operations setup into independent Branch setups, keeps every
 *    balance/movement/snapshot, and its composite foreign keys reject cross-Branch Ingredient references;
 * B/C. racing Product + Operations setup copies (same and opposite directions) leave exactly one configuration, never
 *    copy stock and never deadlock;
 * D. Pay Now vs Remove from Branch: the sale commits entirely before the removal or fails cleanly after it;
 * E. Pay Now vs Recipe edit: the Order snapshot holds one coherent recipe version, never a mix;
 * F. (Phase 19) Copy with Replace vs a Recipe save on a conflicting Product: serialized, skipped and reported, never both.
 */

use App\Actions\Catalog\ConfigureBranchAssortment;
use App\Actions\Operations\AdjustIngredientStock;
use App\Actions\Operations\CopyOperationsSetup;
use App\Actions\Operations\SaveRecipe;
use App\Actions\Orders\PayNowOrder;
use App\Models\Branch;
use App\Models\BranchIngredientStock;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Ingredient;
use App\Models\IngredientMovement;
use App\Models\OperationPlan;
use App\Models\OperationPlanProduct;
use App\Models\Order;
use App\Models\OrderRecipeSnapshot;
use App\Models\OrderRecipeSnapshotLine;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductModifierEffect;
use App\Models\Recipe;
use App\Models\RecipeLine;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\ExactQuantity;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;
use Tests\OperationsScenario;

require dirname(__DIR__).'/vendor/autoload.php';

function bopsVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
bopsVerify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
bopsVerify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
bopsVerify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
bopsVerify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$worker = str_starts_with($argv[1] ?? '', '--');
$schema = $worker ? ($argv[2] ?? '') : 'bops_'.bin2hex(random_bytes(8));
bopsVerify(preg_match('/\Abops_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.bops_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('bops_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
bopsVerify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

/** Worker processes: one action on an independent connection, reporting a clean business rejection as a result. */
if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.trim($argv[1], '-').'_'.($argv[4] ?? '0')]);
    $payload = json_decode(base64_decode($argv[3], true), true, flags: JSON_THROW_ON_ERROR);
    $actor = User::query()->findOrFail($payload['actor']);
    if (isset($payload['session_branch'])) {
        session([ActiveBranchContext::SESSION_KEY => $payload['session_branch']]);
    }
    $branch = fn (string $key): Branch => Branch::query()->findOrFail($payload[$key]);
    try {
        $result = match ($argv[1]) {
            '--copy-products' => app(ConfigureBranchAssortment::class)->copy($actor, $branch('source'), $branch('destination'), $payload['products'], $payload['overwrite'], true),
            '--copy-setup' => app(CopyOperationsSetup::class)->execute($actor, $branch('source'), $branch('destination'), $payload['sections'], $payload['replace']),
            '--remove' => app(ConfigureBranchAssortment::class)->remove($actor, $branch('branch'), [$payload['product']]),
            '--pay' => ['order_id' => app(PayNowOrder::class)->execute($actor, $branch('branch'), [
                'order_type' => 'take_out', 'customer_label' => 'Race', 'items' => [$payload['line']], 'payment_method' => 'cash',
                'cash_received' => '9999.00', 'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(),
            ])->id],
            '--recipe' => ['recipe_id' => app(SaveRecipe::class)->execute($actor, Product::query()->findOrFail($payload['product']), [
                'size_option_id' => $payload['size'], 'lines' => $payload['lines'],
            ])?->id],
            default => throw new RuntimeException('Unknown worker.'),
        };
        $outcome = ['ok' => true, 'result' => $result];
    } catch (ValidationException $exception) {
        $outcome = ['ok' => false, 'message' => collect($exception->errors())->flatten()->first()];
    }
    echo json_encode([...$outcome, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$environment = [
    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
    'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
    'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
    'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
];

/**
 * Starts the workers in order while this connection holds $locked FOR UPDATE, waits until each is blocked behind it (so
 * the queue order is the start order), releases, and returns every worker's decoded result.
 *
 * @param  list<array{0: string, 1: array<string, mixed>}>  $jobs
 * @return list<array<string, mixed>>
 */
function bopsRace(Connection $observer, string $schema, array $environment, Branch $locked, array $jobs): array
{
    DB::beginTransaction();
    Branch::query()->whereKey($locked->id)->lockForUpdate()->firstOrFail();
    $processes = [];
    foreach ($jobs as $index => [$mode, $payload]) {
        $process = new Process([
            PHP_BINARY, __FILE__, $mode, $schema, base64_encode(json_encode($payload, JSON_THROW_ON_ERROR)), (string) $index,
        ], dirname(__DIR__), $environment, timeout: 55);
        $process->start();
        $processes[] = $process;
        $deadline = hrtime(true) + 20_000_000_000;
        do {
            $waiting = $observer->select(
                "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
                [$schema.'%'],
            );
            bopsVerify(hrtime(true) < $deadline, 'Worker '.$mode.' never reached the Branch lock: '.$process->getErrorOutput());
            usleep(10_000);
        } while (count($waiting) < count($processes));
    }
    DB::commit();

    $results = [];
    foreach ($processes as $index => $process) {
        bopsVerify($process->wait() === 0, 'Worker '.$jobs[$index][0].' failed (a deadlock or error): '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    bopsVerify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers did not use independent connections.');

    return $results;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;

    /**
     * A. Cutover. The full schema migrates and the cutover (the newest migration) rolls back cleanly while no
     * Branch-owned setup exists; legacy data is then seeded and only the cutover runs forward on it.
     */
    bopsVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migration failed.');
    /** Relative to the cutover, so later migrations (Phase 19 indexes, ...) roll back first and never shift this step. */
    $cutoverSteps = count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_25_112126'));
    bopsVerify(Artisan::call('migrate:rollback', ['--step' => $cutoverSteps, '--force' => true, '--no-interaction' => true]) === 0, 'The cutover did not roll back on an empty schema.');
    bopsVerify(DB::getSchemaBuilder()->hasColumn('products', 'no_recipe_needed') && ! DB::getSchemaBuilder()->hasColumn('ingredients', 'branch_id'), 'The rollback did not restore the legacy schema.');
    $now = now();
    $legacyOwner = User::factory()->create();
    $cMain = Branch::factory()->create(['code' => 'CMAIN', 'created_at' => $now->copy()->subDays(2)]);
    $cQave = Branch::factory()->create(['code' => 'CQAVE', 'created_at' => $now->copy()->subDay()]);
    $legacyProduct = Product::factory()->create(['name' => 'Legacy Lemon Yakult']);
    $insert = function (string $table, array $row): string {
        $row['id'] ??= (string) Str::uuid();
        DB::table($table)->insert($row);

        return $row['id'];
    };
    $stamps = ['created_at' => $now, 'updated_at' => $now];
    $lemon = $insert('ingredients', ['name' => 'Lemon', 'icon' => 'box', 'base_unit' => 'pc', 'target_quantity' => '10', 'purchase_unit_name' => 'pc', 'purchase_unit_size' => '1', 'purchase_unit_cost' => '10.00', 'replenishment_rule' => 'top_up', 'created_by_user_id' => $legacyOwner->id, ...$stamps]);
    $plan = $insert('operation_plans', ['name' => 'Drinks', 'icon' => 'glass', 'created_by_user_id' => $legacyOwner->id, ...$stamps]);
    $insert('operation_plan_products', ['operation_plan_id' => $plan, 'product_id' => $legacyProduct->id, ...$stamps]);
    $insert('operation_plan_ingredients', ['operation_plan_id' => $plan, 'ingredient_id' => $lemon, ...$stamps]);
    $recipe = $insert('recipes', ['product_id' => $legacyProduct->id, 'size_key' => 'base', ...$stamps]);
    $insert('recipe_lines', ['recipe_id' => $recipe, 'ingredient_id' => $lemon, 'quantity' => '0.5000', ...$stamps]);
    foreach ([[$cMain, '10.0000'], [$cQave, '4.5000']] as [$branch, $onHand]) {
        $insert('branch_ingredient_stocks', ['branch_id' => $branch->id, 'ingredient_id' => $lemon, 'on_hand' => $onHand, 'version' => 1, ...$stamps]);
        $insert('ingredient_movements', ['branch_id' => $branch->id, 'ingredient_id' => $lemon, 'movement_type' => 'opening_balance', 'quantity_delta' => $onHand, 'balance_after' => $onHand, 'operation_plan_id' => $plan, 'created_at' => $now]);
    }
    $legacySession = StoreSession::factory()->for($cQave)->create(['opened_by_user_id' => $legacyOwner->id]);
    $legacyOrder = Order::factory()->for($cQave)->create(['store_session_id' => $legacySession->id, 'commercial_status' => 'completed', 'payment_status' => 'paid', 'committed_at' => $now]);
    $snapshot = $insert('order_recipe_snapshots', ['order_id' => $legacyOrder->id, 'branch_id' => $cQave->id, 'product_id' => $legacyProduct->id, 'size_key' => 'base', 'product_name_snapshot' => 'Legacy Lemon Yakult', 'recipe_state' => 'recipe', 'operation_plan_id' => $plan, 'created_at' => $now]);
    $insert('order_recipe_snapshot_lines', ['order_recipe_snapshot_id' => $snapshot, 'ingredient_id' => $lemon, 'quantity_per_unit' => '0.5000', 'cost_basis_cents' => 1000, 'cost_basis_quantity' => '1.0000']);
    $legacyTotals = DB::table('branch_ingredient_stocks')->orderBy('on_hand')->pluck('on_hand')->all();

    bopsVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'The cutover migration failed.');

    $qaveLemon = DB::table('ingredients')->where('branch_id', $cQave->id)->sole();
    bopsVerify(DB::table('ingredients')->where('branch_id', $cMain->id)->sole()->id === $lemon, 'The oldest Branch keeps the original Ingredient id.');
    bopsVerify($qaveLemon->lineage_id === $lemon, 'The copy remembers its lineage.');
    bopsVerify(DB::table('branch_ingredient_stocks')->orderBy('on_hand')->pluck('on_hand')->all() === $legacyTotals, 'Balances must be unchanged.');
    bopsVerify(DB::table('branch_ingredient_stocks')->where('branch_id', $cQave->id)->value('ingredient_id') === $qaveLemon->id, 'CQAVE stock must point at its own Ingredient.');
    bopsVerify(DB::table('order_recipe_snapshot_lines')->where('order_recipe_snapshot_id', $snapshot)->value('ingredient_id') === $qaveLemon->id, 'CQAVE snapshot must point at its own Ingredient.');
    bopsVerify(DB::table('order_recipe_snapshots')->where('id', $snapshot)->value('operation_plan_id') === DB::table('operation_plans')->where('branch_id', $cQave->id)->value('id'), 'CQAVE snapshot must point at its own Plan.');
    bopsVerify(DB::table('recipe_lines')->join('recipes', 'recipes.id', '=', 'recipe_lines.recipe_id')->join('ingredients', 'ingredients.id', '=', 'recipe_lines.ingredient_id')
        ->whereColumn('recipes.branch_id', '<>', 'ingredients.branch_id')->doesntExist(), 'No recipe line may use another Branch Ingredient.');
    bopsVerify(BranchProduct::query()->where('product_id', $legacyProduct->id)->count() === 2, 'Both existing Branches keep selling the Product.');
    bopsVerify(DB::table('products')->count() === 1 && ! DB::getSchemaBuilder()->hasColumn('products', 'no_recipe_needed'), 'The global catalog is not duplicated and the recipe mode moved.');
    $long = array_column(DB::select("SELECT conname AS name FROM pg_constraint WHERE connamespace = ?::regnamespace AND length(conname) >= 63 AND (conname LIKE '%branch%' OR conname LIKE '%lineage%')
        UNION SELECT indexname FROM pg_indexes WHERE schemaname = ? AND length(indexname) >= 63 AND (indexname LIKE '%branch%' OR indexname LIKE '%lineage%')", [$schema, $schema]), 'name');
    /** Only the Phase 16E working-list unique key was already truncated before this migration. */
    $long = array_values(array_diff($long, ['pamamalengke_list_entries_branch_id_operation_plan_id_entry_typ']));
    bopsVerify($long === [], 'A new identifier reached the PostgreSQL length limit: '.json_encode($long));
    foreach (['branch_ingredient_stocks_branch_ingredient_foreign', 'ingredient_movements_branch_ingredient_foreign', 'operation_plan_products_branch_plan_foreign'] as $constraint) {
        /** Scoped to the isolated schema: the migrated development schema has the same constraint names. */
        bopsVerify(DB::selectOne('SELECT COUNT(*) AS total FROM pg_constraint WHERE connamespace = ?::regnamespace AND conname = ?', [$schema, $constraint])->total === 1, "Constraint {$constraint} is missing.");
    }
    try {
        DB::transaction(fn () => DB::table('branch_ingredient_stocks')->insert(['id' => (string) Str::uuid(), 'branch_id' => $cQave->id, 'ingredient_id' => $lemon, 'on_hand' => '1', 'version' => 0, ...$stamps]));
        bopsVerify(false, 'A CQAVE balance of a CMAIN Ingredient must be rejected by the database.');
    } catch (QueryException $exception) {
        bopsVerify($exception->getCode() === '23503', 'Cross-Branch stock must fail on the composite foreign key, got '.$exception->getCode());
    }
    $cutover = 'rollback on an empty schema restored the legacy schema; the cutover kept balances/snapshots, re-pointed CQAVE to its own copies; composite FKs reject cross-Branch stock; no truncated identifiers';

    /** B/C. A realistic MAIN setup (recipes per size, add-on effects, plans) and a new TEST Branch. */
    $ops = OperationsScenario::create()->withAddOns();
    $test = Branch::factory()->create(['code' => 'TEST', 'name' => 'Test']);
    bopsVerify(BranchProduct::query()->where('branch_id', $test->id)->doesntExist() && Ingredient::query()->where('branch_id', $test->id)->doesntExist(), 'A new Branch starts clean.');
    $counts = fn (): array => [Product::query()->count(), DB::table('categories')->count(), DB::table('modifier_groups')->count(), DB::table('modifier_options')->count()];
    $catalogBefore = $counts();
    $productIds = [$ops->lemonYakult->id, $ops->tapsilog->id];
    $copyResults = bopsRace($observer, $schema, $environment, $test, [
        ['--copy-products', ['actor' => $ops->owner->id, 'source' => $ops->branch->id, 'destination' => $test->id, 'products' => $productIds, 'overwrite' => false]],
        ['--copy-products', ['actor' => $ops->owner->id, 'source' => $ops->branch->id, 'destination' => $test->id, 'products' => array_reverse($productIds), 'overwrite' => false]],
        ['--copy-setup', ['actor' => $ops->owner->id, 'source' => $ops->branch->id, 'destination' => $test->id, 'sections' => ['plans', 'ingredients', 'recipes'], 'replace' => true]],
        /** The opposite direction at the same time: Branch locks are taken in id order, so no deadlock. */
        ['--copy-setup', ['actor' => $ops->owner->id, 'source' => $test->id, 'destination' => $ops->branch->id, 'sections' => ['recipes'], 'replace' => false]],
    ]);
    bopsVerify(collect($copyResults)->every(fn (array $result): bool => $result['ok']), 'Every copy must finish cleanly: '.json_encode($copyResults));
    bopsVerify($counts() === $catalogBefore, 'Copies never duplicate Products, Categories or Modifier Groups/Options.');
    bopsVerify(BranchProduct::query()->where('branch_id', $test->id)->count() === 2, 'Exactly one TEST membership per Product.');
    bopsVerify(Ingredient::query()->where('branch_id', $test->id)->count() === 7
        && Ingredient::query()->where('branch_id', $test->id)->distinct()->count('lineage_id') === 7, 'Exactly one TEST Ingredient per source Ingredient.');
    bopsVerify(OperationPlan::query()->where('branch_id', $test->id)->count() === 2 && OperationPlanProduct::query()->where('branch_id', $test->id)->count() === 2, 'Exactly one TEST Plan per source Plan.');
    bopsVerify(Recipe::query()->where('branch_id', $test->id)->count() === 3 && ProductModifierEffect::query()->where('branch_id', $test->id)->count() === 2, 'Exactly one TEST recipe per size and one effect per add-on.');
    bopsVerify(RecipeLine::query()->whereIn('recipe_id', Recipe::query()->where('branch_id', $test->id)->select('id'))->whereNotIn('ingredient_id', Ingredient::query()->where('branch_id', $test->id)->select('id'))->doesntExist(), 'TEST recipes use only TEST Ingredients.');
    bopsVerify(BranchIngredientStock::query()->where('branch_id', $test->id)->doesntExist() && IngredientMovement::query()->where('branch_id', $test->id)->doesntExist(), 'Copies never create stock or movements.');
    bopsVerify(Recipe::query()->where('branch_id', $ops->branch->id)->count() === 3, 'The reverse copy kept MAIN recipes as they were.');
    $copy = '4 racing copies (2 product+ops, 1 replace, 1 reverse) left one TEST setup (2 products, 7 ingredients, 2 plans, 3 recipes, 2 effects), no stock';

    /** D. TEST stock for Tapsilog, then Pay Now vs Remove from Branch in both queue orders. */
    $testCashier = $ops->user('cashier', $test);
    StoreSession::factory()->for($test)->create(['opened_by_user_id' => $testCashier->id]);
    session([ActiveBranchContext::SESSION_KEY => $test->id]);
    foreach (['Rice' => '100000', 'Egg' => '1000', 'Purified Water' => '100000'] as $name => $quantity) {
        app(AdjustIngredientStock::class)->execute($ops->owner, Ingredient::query()->where('branch_id', $test->id)->where('name', $name)->sole(), [
            'mode' => 'count', 'quantity' => $quantity, 'reason' => 'Opening count', 'idempotency_key' => (string) Str::uuid(),
        ]);
    }
    $rice = fn (): int => ExactQuantity::parse(BranchIngredientStock::query()->where('branch_id', $test->id)
        ->where('ingredient_id', Ingredient::query()->where('branch_id', $test->id)->where('name', 'Rice')->value('id'))->value('on_hand'));
    $outcomes = [];
    $sold = 0;
    foreach ([['pay', 'remove'], ['remove', 'pay'], ['pay', 'remove'], ['remove', 'pay']] as $round => $order) {
        if (BranchProduct::query()->where('branch_id', $test->id)->where('product_id', $ops->tapsilog->id)->doesntExist()) {
            app(ConfigureBranchAssortment::class)->add($ops->owner, $test, [$ops->tapsilog->id]);
        }
        $ordersBefore = Order::query()->where('branch_id', $test->id)->whereNotNull('committed_at')->count();
        $paymentsBefore = Payment::query()->count();
        $jobs = array_map(fn (string $kind): array => $kind === 'pay'
            ? ['--pay', ['actor' => $testCashier->id, 'branch' => $test->id, 'line' => $ops->line($ops->tapsilog, 1)]]
            : ['--remove', ['actor' => $ops->owner->id, 'branch' => $test->id, 'product' => $ops->tapsilog->id]], $order);
        $results = array_combine($order, bopsRace($observer, $schema, $environment, $test, $jobs));
        bopsVerify($results['remove']['ok'] && $results['remove']['result']['removed'] === 1, 'The removal always succeeds.');
        if ($results['pay']['ok']) {
            $orderId = $results['pay']['result']['order_id'];
            $sold++;
            bopsVerify(IngredientMovement::query()->where('order_id', $orderId)->count() === 3 && Payment::query()->where('order_id', $orderId)->exists(), 'A committed sale is complete.');
            $outcomes[] = 'sale-then-remove';
        } else {
            bopsVerify(str_contains((string) $results['pay']['message'], 'no longer available'), 'A sale after the removal fails cleanly: '.$results['pay']['message']);
            bopsVerify(Order::query()->where('branch_id', $test->id)->whereNotNull('committed_at')->count() === $ordersBefore && Payment::query()->count() === $paymentsBefore, 'A rejected sale leaves no Order or Payment.');
            $outcomes[] = 'remove-then-reject';
        }
        bopsVerify($rice() === ExactQuantity::fromInput('100000') - $sold * ExactQuantity::fromInput('200'), 'Ingredient stock moves only for committed sales (round '.$round.').');
        bopsVerify(BranchProduct::query()->where('branch_id', $test->id)->where('product_id', $ops->tapsilog->id)->doesntExist(), 'The Product is out of TEST after every round.');
    }
    bopsVerify(in_array('sale-then-remove', $outcomes, true) && in_array('remove-then-reject', $outcomes, true), 'Both serialized outcomes must be exercised: '.implode(', ', $outcomes));
    $remove = '4 rounds Pay Now vs Remove: '.implode(', ', $outcomes).'; no partial order, payment or stock movement';

    /** E. Pay Now vs Recipe edit at MAIN: the snapshot is exactly one recipe version. */
    $medium = $ops->sizes['m']->id;
    $ingredientId = fn (string $key): string => $ops->ingredients[$key]->id;
    $versions = [
        'v1' => [$ingredientId('lemon') => '0.5', $ingredientId('yakult') => '1', $ingredientId('syrup') => '30', $ingredientId('water') => '250'],
        'v2' => [$ingredientId('lemon') => '0.75', $ingredientId('yakult') => '1', $ingredientId('water') => '300'],
    ];
    $normalize = fn (array $lines): array => collect($lines)->mapWithKeys(fn ($quantity, $id): array => [(string) $id => ExactQuantity::parse((string) $quantity)])->sortKeys()->all();
    $current = 'v1';
    $recipeOutcomes = [];
    foreach ([['pay', 'recipe'], ['recipe', 'pay'], ['pay', 'recipe'], ['recipe', 'pay']] as $order) {
        $target = $current === 'v1' ? 'v2' : 'v1';
        $jobs = array_map(fn (string $kind): array => $kind === 'pay'
            ? ['--pay', ['actor' => $ops->cashier->id, 'branch' => $ops->branch->id, 'line' => $ops->line($ops->lemonYakult, 1, 'm')]]
            : ['--recipe', ['actor' => $ops->owner->id, 'session_branch' => $ops->branch->id, 'product' => $ops->lemonYakult->id, 'size' => $medium,
                'lines' => collect($versions[$target])->map(fn (string $quantity, string $id): array => ['ingredient_id' => $id, 'quantity' => $quantity])->values()->all()]], $order);
        $results = array_combine($order, bopsRace($observer, $schema, $environment, $ops->branch, $jobs));
        bopsVerify($results['pay']['ok'] && $results['recipe']['ok'], 'Both the sale and the recipe edit succeed: '.json_encode($results));
        $snapshotLines = $normalize(OrderRecipeSnapshotLine::query()->whereIn('order_recipe_snapshot_id', OrderRecipeSnapshot::query()->where('order_id', $results['pay']['result']['order_id'])->select('id'))
            ->pluck('quantity_per_unit', 'ingredient_id')->all());
        $expected = $order[0] === 'pay' ? $current : $target;
        bopsVerify($snapshotLines === $normalize($versions[$expected]), 'The snapshot must be exactly recipe '.$expected.', never a mix: '.json_encode($snapshotLines));
        $movements = IngredientMovement::query()->where('order_id', $results['pay']['result']['order_id'])->pluck('quantity_delta', 'ingredient_id')
            ->map(fn ($delta): int => -ExactQuantity::parse((string) $delta))->sortKeys()->all();
        bopsVerify($movements === $snapshotLines, 'Movements must equal the snapshot recipe.');
        bopsVerify($normalize(RecipeLine::query()->whereIn('recipe_id', Recipe::query()->where('branch_id', $ops->branch->id)->where('product_id', $ops->lemonYakult->id)->where('size_key', $medium)->select('id'))
            ->pluck('quantity', 'ingredient_id')->all()) === $normalize($versions[$target]), 'The edit leaves exactly the new recipe.');
        $recipeOutcomes[] = $order[0].'-first:'.$expected;
        $current = $target;
    }
    $recipeEdit = '4 rounds Pay Now vs Recipe edit: '.implode(', ', $recipeOutcomes).'; snapshots were whole versions';

    /**
     * F. Phase 19: Copy with Replace vs a Recipe save on the Product it conflicts on. MAIN sells Coke from Product stock;
     * TEST is given a Coke recipe concurrently. Whichever commits first wins: the copy tracks Coke at TEST (the later
     * recipe is rejected) or the recipe exists (the copy skips Coke and reports it). TEST never ends with both, and the
     * other selected Product is copied every time.
     */
    $testWater = Ingredient::query()->where('branch_id', $test->id)->where('name', 'Purified Water')->value('id');
    $testCoke = fn (): ?BranchProduct => BranchProduct::query()->where('branch_id', $test->id)->where('product_id', $ops->coke->id)->first();
    $cokeRecipes = fn (): int => Recipe::query()->where('branch_id', $test->id)->where('product_id', $ops->coke->id)->count();
    $conflictOutcomes = [];
    foreach ([['copy', 'recipe'], ['recipe', 'copy'], ['copy', 'recipe'], ['recipe', 'copy']] as $order) {
        Recipe::query()->where('branch_id', $test->id)->where('product_id', $ops->coke->id)->delete();
        if ($testCoke() === null) {
            app(ConfigureBranchAssortment::class)->add($ops->owner, $test, [$ops->coke->id]);
        }
        $testCoke()?->update(['tracks_inventory' => false]);
        $jobs = array_map(fn (string $kind): array => $kind === 'copy'
            ? ['--copy-products', ['actor' => $ops->owner->id, 'source' => $ops->branch->id, 'destination' => $test->id,
                'products' => [$ops->coke->id, $ops->lemonYakult->id], 'overwrite' => true]]
            : ['--recipe', ['actor' => $ops->owner->id, 'session_branch' => $test->id, 'product' => $ops->coke->id, 'size' => null,
                'lines' => [['ingredient_id' => $testWater, 'quantity' => '330']]]], $order);
        $results = array_combine($order, bopsRace($observer, $schema, $environment, $test, $jobs));
        bopsVerify($results['copy']['ok'], 'The copy always finishes (a conflict is skipped, never an abort): '.json_encode($results['copy']));
        $copyResult = $results['copy']['result'];
        $tracks = (bool) $testCoke()?->tracks_inventory;
        bopsVerify(! ($tracks && $cokeRecipes() > 0), 'TEST must never track Coke stock and have a Coke recipe at once.');
        if ($order[0] === 'copy') {
            bopsVerify($tracks && $cokeRecipes() === 0 && ! $results['recipe']['ok'] && $copyResult['conflicts'] === [], 'Copy first: Coke tracks stock and the later recipe is rejected: '.json_encode($results));
            $conflictOutcomes[] = 'copy-first:recipe-rejected';
        } else {
            bopsVerify($results['recipe']['ok'] && ! $tracks && $cokeRecipes() === 1
                && array_column($copyResult['conflicts'], 'product_id') === [$ops->coke->id], 'Recipe first: the copy skips Coke and reports it: '.json_encode($results));
            $conflictOutcomes[] = 'recipe-first:coke-skipped';
        }
        bopsVerify(2 - count($copyResult['conflicts']) === $copyResult['copied'] + $copyResult['overwritten'], 'Every non-conflicting Product is copied.');
        bopsVerify(BranchInventory::query()->where('branch_id', $test->id)->doesntExist(), 'The copy never creates Product stock.');
    }
    $conflict = '4 rounds Replace copy vs Recipe save: '.implode(', ', $conflictOutcomes).'; never both Product stock and a recipe';

    echo 'Branch operations PostgreSQL verification passed:'.PHP_EOL
        .'  A. '.$cutover.PHP_EOL
        .'  B/C. '.$copy.PHP_EOL
        .'  D. '.$remove.PHP_EOL
        .'  E. '.$recipeEdit.PHP_EOL
        .'  F. '.$conflict.PHP_EOL
        .'  No deadlock in any race.'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
    bopsVerify($observer->selectOne('SELECT COUNT(*) AS total FROM pg_namespace WHERE nspname = ?', [$schema])->total === 0, 'Isolated schema was not dropped.');
}
