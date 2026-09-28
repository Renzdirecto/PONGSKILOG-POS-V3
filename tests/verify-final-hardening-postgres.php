<?php

/**
 * Opt-in real PostgreSQL verification of the Phase 20 races: DB_URL=null php tests/verify-final-hardening-postgres.php
 *
 * Creates and removes only a random phase20_* schema on a loopback PostgreSQL server. The configured public schema is
 * never migrated, truncated or written. Every race is forced into a known order: the parent holds a row lock, starts
 * the workers one by one (each is proven to be waiting before the next starts, so PostgreSQL queues them in that
 * order) and then releases it.
 *
 * A  Two Store Opens at once → one open Store Session, both callers get it, one audit.
 * B  Stock Correction vs a sale of the last unit, in both orders → exactly one wins, stock never below zero; a found-
 *    stock increase and a sale both apply.
 * C  Pamamalengke funded by the OPEN session vs Close Store, in both orders → purchase first: Close counts it; Close
 *    first: the purchase is refused ("closed while you were confirming"), nothing is written.
 * D  Pamamalengke allocated to a CLOSED session → no Store Purchase, the closed reconciliation is byte-for-byte kept.
 */

use App\Actions\Operations\ConfirmPamamalengke;
use App\Actions\Orders\PayNowOrder;
use App\Actions\StoreSessions\CloseStoreSession;
use App\Actions\StoreSessions\OpenStoreSession;
use App\Actions\StoreSessions\RecordStoreSessionInventoryAdjustment;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\OperationPlan;
use App\Models\PamamalengkePurchase;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Connection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;
use Tests\OperationsScenario;

require dirname(__DIR__).'/vendor/autoload.php';

function verifyPhase20(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<Process> $processes */
function awaitPhase20Locks(Connection $observer, string $applicationPrefix, array $processes): void
{
    $deadline = hrtime(true) + 20_000_000_000;

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
            verifyPhase20($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verifyPhase20(hrtime(true) < $deadline, 'Timed out waiting for concurrent lock requests.');
        usleep(10_000);
    } while (true);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verifyPhase20(! $app->configurationIsCached(), 'Clear cached configuration before local verification.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL.$exception->getTraceAsString().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
verifyPhase20(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
verifyPhase20(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
verifyPhase20(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');
verifyPhase20(ctype_digit((string) $connection['port']), 'A single numeric local port is required.');

$worker = ($argv[1] ?? null) === '--worker';
$schema = $worker ? ($argv[2] ?? '') : 'phase20_'.bin2hex(random_bytes(8));
verifyPhase20(preg_match('/\Aphase20_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase20_admin' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase20_admin');
$identity = $observer->selectOne('SELECT current_database() AS database, host(inet_server_addr()) AS host, inet_server_port() AS port');
verifyPhase20(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

if ($worker) {
    verifyPhase20($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 1, 'Missing isolated schema.');
    DB::statement("SET lock_timeout = '20s'");
    DB::statement("SET statement_timeout = '25s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$argv[3]]);
    [$action, $userId, $branchId] = [$argv[4], $argv[5], $argv[6]];
    $user = User::query()->findOrFail($userId);
    $branch = Branch::query()->findOrFail($branchId);
    session([ActiveBranchContext::SESSION_KEY => $branch->id]);

    try {
        $result = match ($action) {
            'open' => app(OpenStoreSession::class)->execute($user, $branch, $argv[7], '0.00')->id,
            'sale' => app(PayNowOrder::class)->execute($user, $branch, [
                'order_type' => 'take_out', 'customer_label' => 'Race', 'payment_method' => 'cash', 'cash_received' => '500.00',
                'cashless_amount' => null, 'idempotency_key' => (string) Str::uuid(),
                'items' => [['product_id' => $argv[7], 'quantity' => 1, 'notes' => null, 'modifiers' => []]],
            ])->id,
            'correction' => app(RecordStoreSessionInventoryAdjustment::class)->execute($user, $branch, [
                'idempotency_key' => (string) Str::uuid(), 'direction' => $argv[8],
                'reason_code' => $argv[8] === 'increase' ? 'found_stock' : 'missing_stock',
                'product_id' => $argv[7], 'quantity' => (int) $argv[9], 'note' => null,
            ])->id,
            'close' => app(CloseStoreSession::class)->execute($user, $branch, [
                'idempotency_key' => (string) Str::uuid(), 'store_session_id' => $argv[7],
                'closing_cash_amount' => $argv[8], 'closing_cashless_amount' => '0.00', 'closing_note' => null,
            ])['session']->id,
            'pamamalengke' => app(ConfirmPamamalengke::class)->execute($user, OperationPlan::query()->findOrFail($argv[7]), [
                'idempotency_key' => (string) Str::uuid(), 'payment_source' => 'cash',
                'funding_store_session_id' => $argv[8], 'funding_session_status' => $argv[9],
                'items' => [['type' => 'ingredient', 'ingredient_id' => $argv[10], 'actual_quantity' => '2', 'actual_unit_cost' => '10.00']],
            ])->id,
        };
        echo json_encode(['status' => 200, 'id' => $result], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (HttpExceptionInterface $exception) {
        echo json_encode(['status' => $exception->getStatusCode(), 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (ValidationException $exception) {
        echo json_encode(['status' => 422, 'errors' => $exception->errors()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (AuthorizationException) {
        echo json_encode(['status' => 403], JSON_THROW_ON_ERROR).PHP_EOL;
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
    verifyPhase20(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verifyPhase20(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');

    /** Phase 20 migrations: fresh, rollback and reapply on PostgreSQL (constraint swap, composite FK, new columns). */
    $columns = fn (): array => [
        DB::getSchemaBuilder()->hasColumn('store_session_inventory_adjustments', 'direction'),
        DB::getSchemaBuilder()->hasColumn('pamamalengke_purchases', 'payment_source'),
        DB::getSchemaBuilder()->hasColumn('users', 'preferred_name'),
        DB::getSchemaBuilder()->hasColumn('branches', 'receipt_layout') && DB::getSchemaBuilder()->hasColumn('branches', 'image_path'),
    ];
    $fundingForeignKey = fn (): bool => DB::selectOne("SELECT count(*) AS count FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace WHERE n.nspname = ? AND c.conname = 'pamamalengke_purchases_branch_session_foreign'", [$schema])->count === 1;
    verifyPhase20($columns() === [true, true, true, true] && $fundingForeignKey(), 'Fresh Phase 20 schema is incomplete.');
    $phase20Steps = count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_27_165552'));
    verifyPhase20($phase20Steps === 4, 'Expected four Phase 20 migrations, found '.$phase20Steps.'.');
    verifyPhase20(Artisan::call('migrate:rollback', ['--step' => $phase20Steps, '--force' => true, '--no-interaction' => true]) === 0, 'Phase 20 rollback failed.');
    verifyPhase20($columns() === [false, false, false, false] && ! $fundingForeignKey(), 'Phase 20 schema survived rollback.');
    verifyPhase20(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 20 reapply failed.');
    verifyPhase20($columns() === [true, true, true, true] && $fundingForeignKey(), 'Phase 20 reapply was incomplete.');
    echo 'MIGRATION PASS: Phase 20 fresh, rollback and reapply on PostgreSQL.'.PHP_EOL;

    /**
     * Runs workers in the given order against a parent-held lock: each worker is started only after every earlier one
     * is proven to wait, so PostgreSQL grants the lock in exactly this order once the parent commits.
     *
     * @param  list<list<string>>  $workerArgs
     * @param  list<mixed>  $lockBindings
     * @return list<array<string, mixed>>
     */
    $race = function (string $label, array $workerArgs, string $lockSql, array $lockBindings) use ($schema, $connection, $observer, &$processes): array {
        DB::beginTransaction();
        DB::select($lockSql, $lockBindings);
        $workers = [];
        foreach ($workerArgs as $index => $args) {
            $process = new Process([PHP_BINARY, __FILE__, '--worker', $schema, $schema.'_'.$label.'_'.$index, ...$args], dirname(__DIR__), [
                'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
                'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
                'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
                'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
            ], timeout: 60);
            $processes[] = $process;
            $workers[] = $process;
            $process->start();
            awaitPhase20Locks($observer, $schema.'_'.$label.'_', $workers);
        }
        DB::commit();

        return array_map(function (Process $process): array {
            verifyPhase20($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());

            return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        }, $workers);
    };
    $branchLock = 'SELECT id FROM branches WHERE id = ? FOR UPDATE';

    /** A. Two Store Opens at once. */
    $ops = OperationsScenario::create();
    $second = Branch::factory()->create(['code' => 'EAST', 'name' => 'East']);
    $ops->cashier->branches()->attach($second, ['is_active' => true]);
    $results = $race('open', [
        ['open', (string) $ops->cashier->id, $second->id, '500.00'],
        ['open', (string) $ops->cashier->id, $second->id, '750.00'],
    ], $branchLock, [$second->id]);
    verifyPhase20(array_column($results, 'status') === [200, 200], 'Concurrent opens failed: '.json_encode($results));
    verifyPhase20(count(array_unique(array_column($results, 'id'))) === 1, 'Concurrent opens returned different sessions.');
    verifyPhase20(StoreSession::query()->where('branch_id', $second->id)->where('status', 'open')->count() === 1, 'More than one open Store Session.');
    verifyPhase20(StoreSession::query()->where('branch_id', $second->id)->value('opening_cash_amount') === '500.00', 'The first opener did not win.');
    verifyPhase20(AuditLog::query()->where('action', 'store.opened')->where('branch_id', $second->id)->count() === 1, 'Store opening was audited twice.');
    echo 'A PASS: two concurrent Store Opens create one open Store Session; both callers get it; one audit.'.PHP_EOL;

    /** B. Stock Correction vs a sale of the last unit, both orders; then an increase and a sale both apply. */
    $coke = $ops->coke;
    $setStock = fn (int $onHand) => BranchInventory::query()->where('branch_id', $ops->branch->id)->where('product_id', $coke->id)->update(['on_hand' => $onHand]);
    $onHand = fn (): int => (int) BranchInventory::query()->where('branch_id', $ops->branch->id)->where('product_id', $coke->id)->value('on_hand');
    $correction = fn (string $direction, int $quantity): array => ['correction', (string) $ops->cashier->id, $ops->branch->id, $coke->id, $direction, (string) $quantity];
    $sale = fn (): array => ['sale', (string) $ops->cashier->id, $ops->branch->id, $coke->id];

    $setStock(1);
    $results = $race('correction_first', [$correction('decrease', 1), $sale()], $branchLock, [$ops->branch->id]);
    verifyPhase20(array_column($results, 'status') === [200, 422], 'Correction-first race: '.json_encode($results));
    verifyPhase20($onHand() === 0, 'Correction-first race left '.$onHand().' units.');

    $setStock(1);
    $results = $race('sale_first', [$sale(), $correction('decrease', 1)], $branchLock, [$ops->branch->id]);
    verifyPhase20(array_column($results, 'status') === [200, 422], 'Sale-first race: '.json_encode($results));
    verifyPhase20($onHand() === 0, 'Sale-first race left '.$onHand().' units.');

    $setStock(1);
    $results = $race('found_stock', [$correction('increase', 2), $sale()], $branchLock, [$ops->branch->id]);
    verifyPhase20(array_column($results, 'status') === [200, 200], 'Found stock + sale: '.json_encode($results));
    verifyPhase20($onHand() === 2, 'Found stock + sale left '.$onHand().' units instead of 2.');
    echo 'B PASS: Stock Correction and a sale of the last unit serialize (one wins either way, never below zero); found stock and a sale both apply.'.PHP_EOL;

    /** C1. Pamamalengke (OPEN funding) queued before Close: Close counts the purchase. */
    $lemonId = $ops->ingredients['lemon']->id;
    $open = $ops->session;
    verifyPhase20($open->opening_cash_amount === '1000.00', 'Unexpected scenario opening cash '.$open->opening_cash_amount);
    /** Setup only: B's sales went to the Kitchen, and Close Store requires every committed Kitchen order Done. */
    DB::table('kitchen_tickets')->where('branch_id', $ops->branch->id)->update(['status' => 'done']);
    DB::table('orders')->where('store_session_id', $open->id)->whereNotNull('committed_at')->update(['kitchen_status' => 'done']);
    $cashSales = (int) round(((float) DB::table('payments')->where('store_session_id', $open->id)->where('method', 'cash')->sum('amount')) * 100);
    $expectedCash = number_format((100000 + $cashSales - 2000) / 100, 2, '.', '');
    $results = $race('purchase_first', [
        ['pamamalengke', (string) $ops->owner->id, $ops->branch->id, $ops->drinks->id, $open->id, 'open', $lemonId],
        ['close', (string) $ops->cashier->id, $ops->branch->id, $open->id, $expectedCash],
    ], $branchLock, [$ops->branch->id]);
    verifyPhase20(array_column($results, 'status') === [200, 200], 'Purchase-first race: '.json_encode($results));
    $purchase = PamamalengkePurchase::query()->findOrFail($results[0]['id']);
    $expense = StoreSessionExpense::query()->findOrFail($purchase->store_session_expense_id);
    $closedSession = $open->fresh();
    verifyPhase20($purchase->store_session_id === $open->id && $expense->store_session_id === $open->id && $expense->amount === '20.00', 'The purchase was not a Store Purchase of the open session.');
    verifyPhase20($closedSession->status->value === 'closed' && $closedSession->expected_cash_amount === $expectedCash && $closedSession->cash_variance === '0.00', 'Close did not count the purchase: '.json_encode($closedSession->only(['status', 'expected_cash_amount', 'cash_variance'])));
    echo 'C1 PASS: a purchase that commits first is a Store Purchase of the open session and Close Store counts it (expected cash '.$expectedCash.').'.PHP_EOL;

    /** C2. Close queued before the Pamamalengke: the purchase is refused, nothing is written. */
    $reopened = StoreSession::factory()->for($ops->branch)->create(['opened_by_user_id' => $ops->cashier->id]);
    $purchasesBefore = PamamalengkePurchase::query()->count();
    $expensesBefore = StoreSessionExpense::query()->count();
    $results = $race('close_first', [
        ['close', (string) $ops->cashier->id, $ops->branch->id, $reopened->id, '1000.00'],
        ['pamamalengke', (string) $ops->owner->id, $ops->branch->id, $ops->drinks->id, $reopened->id, 'open', $lemonId],
    ], $branchLock, [$ops->branch->id]);
    verifyPhase20($results[0]['status'] === 200 && $results[1]['status'] === 422, 'Close-first race: '.json_encode($results));
    verifyPhase20(str_contains(json_encode($results[1]['errors'], JSON_THROW_ON_ERROR), 'closed while you were confirming'), 'The refused purchase did not explain the closed session.');
    verifyPhase20(PamamalengkePurchase::query()->count() === $purchasesBefore && StoreSessionExpense::query()->count() === $expensesBefore, 'A refused purchase wrote rows.');
    verifyPhase20($reopened->fresh()->expected_cash_amount === '1000.00', 'The refused purchase changed the closed result.');
    echo 'C2 PASS: once Close Store wins, an OPEN-funded purchase is refused and nothing is written.'.PHP_EOL;

    /** D. Allocation to a CLOSED session keeps its Close Store result byte-for-byte. */
    $sealed = $reopened->fresh();
    $before = DB::table('store_sessions')->where('id', $sealed->id)->first();
    session([ActiveBranchContext::SESSION_KEY => $ops->branch->id]);
    $allocation = app(ConfirmPamamalengke::class)->execute($ops->owner, $ops->drinks, [
        'idempotency_key' => (string) Str::uuid(), 'payment_source' => 'cash',
        'funding_store_session_id' => $sealed->id, 'funding_session_status' => 'closed',
        'items' => [['type' => 'ingredient', 'ingredient_id' => $lemonId, 'actual_quantity' => '2', 'actual_unit_cost' => '10.00']],
    ]);
    verifyPhase20($allocation->store_session_id === $sealed->id && $allocation->store_session_expense_id === null, 'A closed-session allocation created a Store Purchase.');
    verifyPhase20(StoreSessionExpense::query()->where('store_session_id', $sealed->id)->doesntExist(), 'A closed session received an expense.');
    verifyPhase20(json_encode(DB::table('store_sessions')->where('id', $sealed->id)->first()) === json_encode($before), 'The closed Store Session row changed.');
    echo 'D PASS: a purchase allocated to a closed Store Session writes no Store Purchase and leaves the closed result untouched.'.PHP_EOL;

    echo 'PASS: PostgreSQL Phase 20 final hardening races.'.PHP_EOL;
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
        verifyPhase20($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS: removed isolated schema '.$schema.PHP_EOL;
    }
}
