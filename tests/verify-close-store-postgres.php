<?php

/**
 * Opt-in real PostgreSQL verification: php tests/verify-close-store-postgres.php
 *
 * Creates and removes only a random phase15_* schema on a loopback PostgreSQL
 * server. The configured public schema is never migrated, truncated, or written.
 *
 * Every race is forced into a deterministic serial order with a parent-held row lock, and both orders are proven
 * where both are possible: the Close Store transaction either wins the exclusive Session boundary and the competing
 * write is rejected, or the competing write commits first and Close recomputes with it.
 */

use App\Actions\Audit\AuditRecorder;
use App\Actions\Orders\AllocateOrderAdjustment;
use App\Actions\Orders\ArchiveCustomerQrOrder;
use App\Actions\Orders\CommitPayLaterOrder;
use App\Actions\Orders\CreatePosDraftOrder;
use App\Actions\Orders\EditCommittedOrder;
use App\Actions\Orders\LoadCustomerQrOrder;
use App\Actions\Orders\PayNowOrder;
use App\Actions\Orders\RestoreCustomerQrOrder;
use App\Actions\Orders\SettlePayLaterOrder;
use App\Actions\Orders\TransitionKitchenOrder;
use App\Actions\Orders\VoidOrder;
use App\Actions\StoreSessions\CloseStoreSession;
use App\Actions\StoreSessions\RecordStoreSessionExpense;
use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Order;
use App\Models\OrderAdjustment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\StoreSession;
use App\Models\StoreSessionExpense;
use App\Models\User;
use Database\Seeders\RbacSeeder;
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
use Tests\StoreCloseScenario;

require dirname(__DIR__).'/vendor/autoload.php';

function verifyPhase15(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @param list<Process> $processes */
function awaitPhase15Locks(Connection $observer, string $applicationPrefix, array $processes): void
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
            verifyPhase15($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verifyPhase15(hrtime(true) < $deadline, 'Timed out waiting for concurrent lock requests.');
        usleep(10_000);
    } while (true);
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verifyPhase15(! $app->configurationIsCached(), 'Clear cached configuration before local verification.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
verifyPhase15(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
verifyPhase15(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
verifyPhase15(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only literal loopback PostgreSQL hosts are allowed.');
verifyPhase15(ctype_digit((string) $connection['port']), 'A single numeric local port is required.');

$worker = ($argv[1] ?? null) === '--worker';
$schema = $worker ? ($argv[2] ?? '') : 'phase15_'.bin2hex(random_bytes(8));
verifyPhase15(preg_match('/\Aphase15_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase15_admin' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase15_admin');
$identity = $observer->selectOne('SELECT current_database() AS database, host(inet_server_addr()) AS host, inet_server_port() AS port');
verifyPhase15(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

if ($worker) {
    verifyPhase15($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 1, 'Missing isolated schema.');
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '35s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$argv[3]]);
    $user = User::query()->findOrFail($argv[4]);
    $branch = Branch::query()->findOrFail($argv[5]);
    $order = fn (): Order => Order::query()->findOrFail($argv[7]);

    try {
        $result = match ($argv[6]) {
            'close' => app(CloseStoreSession::class)->execute($user, $branch, [
                'idempotency_key' => $argv[7],
                'store_session_id' => $argv[8],
                'closing_cash_amount' => $argv[9],
                'closing_cashless_amount' => $argv[10] ?? '0.00',
                'closing_note' => null,
            ])['session']->only(['id', 'closed_at']),
            'pay_now' => app(PayNowOrder::class)->execute($user, $branch, [
                'order_type' => 'take_out',
                'customer_label' => 'Race',
                'items' => [['product_id' => $argv[7], 'quantity' => 1, 'notes' => null, 'modifiers' => []]],
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => $argv[8] ?? 'cash',
                'cash_received' => match ($argv[8] ?? 'cash') {
                    'cash' => '100.00', 'split' => '60.00', default => null,
                },
                'cashless_amount' => ($argv[8] ?? 'cash') === 'split' ? '40.00' : null,
            ])->only(['id']),
            'pay_later' => app(CommitPayLaterOrder::class)->execute($user, $branch, $order(), [
                'idempotency_key' => (string) Str::uuid(),
            ])->only(['id']),
            'settle' => app(SettlePayLaterOrder::class)->execute($user, $branch, $order(), [
                'idempotency_key' => (string) Str::uuid(),
                'payment_method' => 'cash',
                'cash_received' => '100.00',
                'cashless_amount' => null,
            ])->only(['id']),
            'edit' => (function () use ($user, $branch, $order, $argv): array {
                $target = $order();
                $item = $target->items()->firstOrFail();

                return app(EditCommittedOrder::class)->execute($user, $branch, $target, [
                    'idempotency_key' => (string) Str::uuid(),
                    'expected_version' => $target->version,
                    'order_type' => 'take_out',
                    'customer_label' => $target->customer_label,
                    'branch_table_id' => null,
                    'items' => [['existing_order_item_id' => $item->id, 'product_id' => $item->product_id, 'quantity' => (int) $argv[8], 'notes' => null, 'modifiers' => []]],
                ])->only(['id']);
            })(),
            'void' => app(VoidOrder::class)->execute($user, $branch, $order(), [
                'reason_code' => 'wrong_item',
                'reason_text' => null,
                'authorization_pin' => '1234',
                'idempotency_key' => (string) Str::uuid(),
                'expected_version' => $order()->version,
            ])->only(['id']),
            'kitchen' => app(TransitionKitchenOrder::class)->executeWithResult($user, $branch, $order(), KitchenStatus::from($argv[8]))['order']->only(['id']),
            'expense' => app(RecordStoreSessionExpense::class)->execute($user, $branch, array_filter([
                'idempotency_key' => (string) Str::uuid(),
                'description' => 'Race expense',
                'amount' => '10.00',
                'payment_source' => 'cash',
                'note' => null,
                'restock' => isset($argv[7]),
                'product_id' => $argv[7] ?? null,
                'quantity' => isset($argv[7]) ? 2 : null,
            ], fn (mixed $value): bool => $value !== null))->only(['id']),
            'restore' => app(RestoreCustomerQrOrder::class)->execute($user, $branch, $order())->only(['id']),
            'load' => app(LoadCustomerQrOrder::class)->execute($user, $branch, $order())->only(['id']),
        };
        echo json_encode(['status' => 200, ...$result], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (HttpExceptionInterface $exception) {
        echo json_encode(['status' => $exception->getStatusCode(), 'message' => $exception->getMessage()], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (ValidationException $exception) {
        echo json_encode(['status' => 422, 'errors' => array_keys($exception->errors())], JSON_THROW_ON_ERROR).PHP_EOL;
    } catch (AuthorizationException) {
        echo json_encode(['status' => 403], JSON_THROW_ON_ERROR).PHP_EOL;
    }

    exit(0);
}

$processes = [];
$createdSchema = false;

try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verifyPhase15(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verifyPhase15(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');

    $checks = fn (): array => collect(DB::select(
        "SELECT conname, pg_get_constraintdef(c.oid) AS definition FROM pg_constraint c JOIN pg_namespace n ON n.oid = c.connamespace
         WHERE n.nspname = ? AND c.contype = 'c' AND c.conrelid IN ('store_sessions'::regclass, 'order_adjustments'::regclass)",
        [$schema],
    ))->pluck('definition', 'conname')->all();
    $hasCheck = fn (array $constraints, string $needle): bool => collect($constraints)->contains(fn (string $definition): bool => str_contains($definition, $needle));
    $hasIndex = fn (): bool => DB::selectOne("SELECT count(*) AS count FROM pg_indexes WHERE schemaname = ? AND indexname = 'order_adjustments_store_session_id_order_id_index'", [$schema])->count === 1;
    $after = $checks();
    verifyPhase15(! $hasCheck($after, 'expected_cash_amount >=') && ! $hasCheck($after, 'expected_cashless_amount >='), 'Expected balance checks must allow exact negative math.');
    verifyPhase15($hasCheck($after, 'closing_cash_amount >=') && $hasCheck($after, 'closing_cashless_amount >='), 'Closing balances must stay non-negative.');
    verifyPhase15(isset($after['order_adjustments_allocation_check']), 'Allocation consistency check is missing.');
    verifyPhase15(DB::getSchemaBuilder()->hasColumn('store_sessions', 'reconciliation_snapshot'), 'Snapshot column is missing.');
    verifyPhase15($hasIndex(), 'Session correction index is missing.');
    $phase15Steps = count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_23_053920'));
    verifyPhase15(Artisan::call('migrate:rollback', ['--step' => $phase15Steps, '--force' => true, '--no-interaction' => true]) === 0, 'Phase 15 rollback failed.');
    $rolledBack = $checks();
    verifyPhase15($hasCheck($rolledBack, 'expected_cash_amount >=') && ! isset($rolledBack['order_adjustments_allocation_check']), 'Rollback did not restore the prior schema.');
    verifyPhase15(! DB::getSchemaBuilder()->hasColumn('order_adjustments', 'cash_amount') && ! $hasIndex(), 'Phase 15 schema survived rollback.');
    verifyPhase15(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 15 reapply failed.');
    verifyPhase15($hasIndex() && isset($checks()['order_adjustments_allocation_check']), 'Phase 15 reapply was incomplete.');
    echo 'MIGRATION PASS: fresh, constraints, index, rollback, and reapply.'.PHP_EOL;

    verifyPhase15(Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true, '--no-interaction' => true]) === 0, 'RBAC seed failed.');

    /** Exercise the PostgreSQL cents/grouping SQL with real payment, correction, void and expense history. */
    $math = StoreCloseScenario::create('1000.00', '500.00');
    $math->authorizeVoids();
    $split = $math->payNow(5, 'split', '200.00');
    $math->edit($math->payNow(3, 'cash'), 2);
    $math->void($math->payNow(4, 'cashless'));
    $math->expense('50.00', 'cash');
    $math->expense('30.00', 'cashless');
    $reconciliation = $math->reconciliation();
    verifyPhase15($reconciliation['split'] === ['cash' => '300.00', 'cashless' => '200.00', 'count' => 1], 'Split breakdown was wrong on PostgreSQL.');
    verifyPhase15($reconciliation['corrections'] === ['cash' => '100.00', 'cashless' => '0.00', 'count' => 1], 'Correction totals were wrong on PostgreSQL.');
    verifyPhase15($reconciliation['voids'] === ['cash' => '0.00', 'cashless' => '400.00', 'count' => 1], 'Void reversal was wrong on PostgreSQL.');
    verifyPhase15($reconciliation['expected'] === ['cash' => '1450.00', 'cashless' => '670.00'], 'Expected balances were wrong on PostgreSQL: '.json_encode($reconciliation['expected']));
    $historical = OrderAdjustment::factory()->create([
        'order_id' => $split->id, 'branch_id' => $math->branch->id, 'store_session_id' => $math->session->id,
        'amount' => '50.00', 'cash_amount' => null, 'cashless_amount' => null,
    ]);
    $corrections = $math->preview()['blockers']['corrections'];
    verifyPhase15($corrections['count'] === 1 && $corrections['items'][0]['max_cash'] === '50.00', 'Ambiguous correction was not detected on PostgreSQL.');
    try {
        DB::transaction(fn () => DB::table('order_adjustments')->where('id', $historical->id)->update(['cash_amount' => '10.00', 'cashless_amount' => '10.00']));
        verifyPhase15(false, 'Allocation that does not sum to the correction was accepted.');
    } catch (QueryException) {
    }
    app(AllocateOrderAdjustment::class)->execute($math->cashier, $math->branch, $historical, ['cash_amount' => '20.00']);
    $allocated = $math->reconciliation();
    verifyPhase15($allocated['corrections'] === ['cash' => '120.00', 'cashless' => '30.00', 'count' => 2], 'Allocated correction was not attributed exactly.');
    verifyPhase15($allocated['expected'] === ['cash' => '1430.00', 'cashless' => '640.00'], 'Allocated expected balances were wrong.');
    echo 'RECONCILIATION SQL PASS: split, corrections, voids, expenses and allocation are exact on PostgreSQL.'.PHP_EOL;

    $startWorker = function (string $label, int $index, User $user, Branch $branch, array $arguments) use ($schema, $connection, &$processes): Process {
        $process = new Process([
            PHP_BINARY, __FILE__, '--worker', $schema, $schema.'_'.$label.'_'.$index, (string) $user->id, $branch->id, ...$arguments,
        ], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 60);
        $processes[] = $process;
        $process->start();

        return $process;
    };
    $finish = function (Process $process): array {
        verifyPhase15($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());

        return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    };
    $closeArgs = fn (StoreCloseScenario $scenario, string $cash, string $cashless = '0.00'): array => ['close', (string) Str::uuid(), $scenario->session->id, $cash, $cashless];
    $closed = fn (StoreCloseScenario $scenario): bool => $scenario->session->fresh()->status->value === 'closed';

    /** A: Close vs Close — exactly one close, audit and QR archival effect. */
    foreach (['same_key' => true, 'different_keys' => false] as $label => $sameKey) {
        $race = StoreCloseScenario::create('1000.00', '0.00');
        $qr = $race->submitQr();
        $qrVersion = $qr->fresh()->version;
        $key = (string) Str::uuid();
        DB::beginTransaction();
        Branch::query()->whereKey($race->branch->id)->lockForUpdate()->sole();
        $workers = [];
        foreach ([0, 1] as $index) {
            $workers[] = $startWorker($label, $index, $race->cashier, $race->branch, ['close', $sameKey ? $key : (string) Str::uuid(), $race->session->id, '1000.00']);
        }
        awaitPhase15Locks($observer, $schema.'_'.$label.'_', $workers);
        DB::commit();
        $results = array_map($finish, $workers);
        $statuses = collect($results)->pluck('status')->sort()->values()->all();
        verifyPhase15($statuses === ($sameKey ? [200, 200] : [200, 409]), $label.' close race returned '.json_encode($results));
        if ($sameKey) {
            verifyPhase15($results[0]['closed_at'] === $results[1]['closed_at'], 'Exact replay changed the closing timestamp.');
        }
        verifyPhase15(AuditLog::query()->where('action', 'store.closed')->where('auditable_id', $race->session->id)->count() === 1, $label.' duplicated the close audit.');
        verifyPhase15($closed($race) && StoreSession::query()->where('branch_id', $race->branch->id)->count() === 1, $label.' did not produce exactly one closed session.');
        verifyPhase15($qr->fresh()->archive_reason === 'store_closed' && $qr->fresh()->version === $qrVersion + 1, $label.' archived the QR order more than once.');
    }
    echo 'A PASS: Close vs Close yields one closed session, audit and QR archival (exact retry recovers; competing key gets 409).'.PHP_EOL;

    /** Close wins: it holds Branch + Session exclusively while blocked on a QR row; every competing write then fails. */
    $wins = StoreCloseScenario::create('1000.00', '0.00');
    $wins->authorizeVoids();
    $paid = $wins->payNow(1, 'cash');
    $wins->done($paid);
    $draft = app(CreatePosDraftOrder::class)->execute($wins->cashier, $wins->branch, ['order_type' => 'take_out', 'items' => $wins->items(1)]);
    $archived = $wins->submitQr();
    app(ArchiveCustomerQrOrder::class)->execute($archived, 'cashier_archived');
    $loadable = $wins->submitQr();
    $boundaryQr = $wins->submitQr();
    DB::beginTransaction();
    Order::query()->whereKey($boundaryQr->id)->lockForUpdate()->sole();
    $close = $startWorker('wins_close', 0, $wins->cashier, $wins->branch, $closeArgs($wins, '1100.00'));
    awaitPhase15Locks($observer, $schema.'_wins_close_', [$close]);
    $competitors = [
        'B pay_now' => [$wins->cashier, ['pay_now', $wins->product->id, 'cash']],
        'C pay_later' => [$wins->cashier, ['pay_later', $draft->id]],
        'E edit' => [$wins->cashier, ['edit', $paid->id, '2']],
        'F void' => [$wins->cashier, ['void', $paid->id]],
        'G kitchen' => [$wins->kitchen, ['kitchen', $paid->id, 'ready']],
        'H expense' => [$wins->cashier, ['expense']],
        'I restore' => [$wins->cashier, ['restore', $archived->id]],
        'I load' => [$wins->cashier, ['load', $loadable->id]],
    ];
    $waiting = [];
    foreach (array_values($competitors) as $index => [$user, $arguments]) {
        $waiting[] = $startWorker('wins_writes', $index, $user, $wins->branch, $arguments);
    }
    awaitPhase15Locks($observer, $schema.'_wins_writes_', $waiting);
    DB::commit();
    verifyPhase15($finish($close)['status'] === 200, 'Close did not win the Session boundary.');
    foreach (array_keys($competitors) as $index => $label) {
        $result = $finish($waiting[$index]);
        verifyPhase15(in_array($result['status'], [409, 422], true), $label.' crossed the closed Session boundary: '.json_encode($result));
    }
    $paid->refresh();
    verifyPhase15(Payment::query()->where('store_session_id', $wins->session->id)->count() === 1, 'A payment committed into the closed session.');
    verifyPhase15(StoreSessionExpense::query()->where('store_session_id', $wins->session->id)->doesntExist(), 'An expense committed into the closed session.');
    verifyPhase15($paid->commercial_status === CommercialStatus::Active && $paid->kitchen_status === KitchenStatus::Done && $paid->total === '100.00', 'A void, edit or Kitchen change crossed the closed Session boundary.');
    verifyPhase15($draft->fresh()->committed_at === null, 'Pay Later committed into the closed session.');
    verifyPhase15($archived->fresh()->commercial_status === CommercialStatus::ArchivedUnclaimed && $archived->fresh()->archive_reason === 'cashier_archived', 'An archived QR order was restored after close.');
    verifyPhase15($loadable->fresh()->loaded_by_user_id === null && $loadable->fresh()->archive_reason === 'store_closed', 'A QR order was loaded instead of archived at close.');
    echo 'CLOSE-WINS PASS: B Pay Now, C Pay Later, E edit, F Void, G Kitchen, H Expense and I restore/load all reject after close.'.PHP_EOL;

    /** Write wins: the competing write holds its boundary first, Close waits, recomputes, and includes the effect. */
    $writeFirst = function (string $label, StoreCloseScenario $scenario, Closure $holdLock, User $writer, array $writeArgs, array $closeArguments) use ($observer, $schema, $startWorker, $finish): array {
        DB::beginTransaction();
        $holdLock();
        $write = $startWorker($label.'_write', 0, $writer, $scenario->branch, $writeArgs);
        awaitPhase15Locks($observer, $schema.'_'.$label.'_write_', [$write]);
        $close = $startWorker($label.'_close', 0, $scenario->cashier, $scenario->branch, $closeArguments);
        awaitPhase15Locks($observer, $schema.'_'.$label.'_close_', [$close]);
        DB::commit();

        return [$finish($write), $finish($close)];
    };

    $payFirst = StoreCloseScenario::create('1000.00', '0.00');
    [$write, $closeResult] = $writeFirst('pay_first', $payFirst, fn () => BranchInventory::query()->whereKey($payFirst->stock->id)->lockForUpdate()->sole(), $payFirst->cashier, ['pay_now', $payFirst->product->id, 'cash'], $closeArgs($payFirst, '1100.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 422 && in_array('kitchen', $closeResult['errors'], true) && ! $closed($payFirst), 'B: Close did not recompute after Pay Now: '.json_encode($closeResult));

    $laterFirst = StoreCloseScenario::create('1000.00', '0.00');
    $laterDraft = app(CreatePosDraftOrder::class)->execute($laterFirst->cashier, $laterFirst->branch, ['order_type' => 'take_out', 'items' => $laterFirst->items(1)]);
    [$write, $closeResult] = $writeFirst('pay_later_first', $laterFirst, fn () => BranchInventory::query()->whereKey($laterFirst->stock->id)->lockForUpdate()->sole(), $laterFirst->cashier, ['pay_later', $laterDraft->id], $closeArgs($laterFirst, '1000.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 422 && in_array('outstanding', $closeResult['errors'], true) && ! $closed($laterFirst), 'C: Close ignored a Pay Later commit: '.json_encode($closeResult));

    $settleFirst = StoreCloseScenario::create('1000.00', '0.00');
    $tab = $settleFirst->payLater(1);
    $settleFirst->done($tab);
    [$write, $closeResult] = $writeFirst('settle_first', $settleFirst, fn () => Order::query()->whereKey($tab->id)->lockForUpdate()->sole(), $settleFirst->cashier, ['settle', $tab->id], $closeArgs($settleFirst, '1100.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 200 && $settleFirst->session->fresh()->expected_cash_amount === '1100.00', 'D: Close omitted a settlement: '.json_encode($closeResult));

    $editFirst = StoreCloseScenario::create('1000.00', '0.00');
    $edited = $editFirst->payNow(3, 'cash');
    $editFirst->done($edited);
    [$write, $closeResult] = $writeFirst('edit_first', $editFirst, fn () => Order::query()->whereKey($edited->id)->lockForUpdate()->sole(), $editFirst->cashier, ['edit', $edited->id, '2'], $closeArgs($editFirst, '1200.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 200 && data_get($editFirst->session->fresh()->reconciliation_snapshot, 'corrections.cash') === '100.00', 'E: Close omitted a committed correction: '.json_encode($closeResult));

    $voidFirst = StoreCloseScenario::create('1000.00', '0.00');
    $voidFirst->authorizeVoids();
    $voided = $voidFirst->payNow(2, 'cash');
    $voidFirst->done($voided);
    [$write, $closeResult] = $writeFirst('void_first', $voidFirst, fn () => Order::query()->whereKey($voided->id)->lockForUpdate()->sole(), $voidFirst->cashier, ['void', $voided->id], $closeArgs($voidFirst, '1000.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 200 && data_get($voidFirst->session->fresh()->reconciliation_snapshot, 'voids.cash') === '200.00', 'F: Close omitted a committed Void: '.json_encode($closeResult));

    $kitchenFirst = StoreCloseScenario::create('1000.00', '0.00');
    $ready = $kitchenFirst->payNow(1, 'cash');
    $kitchenFirst->kitchenStatus($ready, KitchenStatus::Ready);
    [$write, $closeResult] = $writeFirst('kitchen_first', $kitchenFirst, fn () => Order::query()->whereKey($ready->id)->lockForUpdate()->sole(), $kitchenFirst->kitchen, ['kitchen', $ready->id, 'done'], $closeArgs($kitchenFirst, '1100.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 200 && $closed($kitchenFirst), 'G: Close did not observe the serialized Done transition: '.json_encode($closeResult));
    $blockedKitchen = StoreCloseScenario::create('1000.00', '0.00');
    $blockedKitchen->kitchenStatus($blockedKitchen->payNow(1, 'cash'), KitchenStatus::Preparing);
    $closeResult = $finish($startWorker('kitchen_active_close', 0, $blockedKitchen->cashier, $blockedKitchen->branch, $closeArgs($blockedKitchen, '1100.00')));
    verifyPhase15($closeResult['status'] === 422 && in_array('kitchen', $closeResult['errors'], true) && ! $closed($blockedKitchen), 'G: Close passed with active Kitchen work.');

    $expenseFirst = StoreCloseScenario::create('1000.00', '0.00');
    [$write, $closeResult] = $writeFirst('expense_first', $expenseFirst, fn () => Product::query()->whereKey($expenseFirst->product->id)->lockForUpdate()->sole(), $expenseFirst->cashier, ['expense', $expenseFirst->product->id], $closeArgs($expenseFirst, '990.00'));
    verifyPhase15($write['status'] === 200 && $closeResult['status'] === 200 && $expenseFirst->session->fresh()->expected_cash_amount === '990.00', 'H: Close omitted a committed Expense: '.json_encode($closeResult));
    echo 'WRITE-FIRST PASS: B/C Pay Now and Pay Later block the recomputed close; D settlement, E correction, F Void, G Done and H Expense are included.'.PHP_EOL;

    /** J: a failure after archiving part of a large QR set rolls every archive back. */
    $bulk = StoreCloseScenario::create('1000.00', '0.00');
    $bulkQr = collect(range(1, 10))->map(fn (): Order => $bulk->submitQr());
    app()->instance(AuditRecorder::class, new class extends AuditRecorder
    {
        public function record(...$arguments): AuditLog
        {
            throw new RuntimeException('Injected audit failure');
        }
    });
    try {
        app(CloseStoreSession::class)->execute($bulk->cashier, $bulk->branch, $bulk->closePayload('1000.00', '0.00'));
        verifyPhase15(false, 'J: injected failure did not abort the close.');
    } catch (RuntimeException $exception) {
        verifyPhase15($exception->getMessage() === 'Injected audit failure', 'J: unexpected failure '.$exception->getMessage());
    } finally {
        app()->forgetInstance(AuditRecorder::class);
        app()->forgetInstance(CloseStoreSession::class);
    }
    verifyPhase15(! $closed($bulk) && $bulk->session->fresh()->closing_cash_amount === null, 'J: session changed after rollback.');
    verifyPhase15(Order::query()->whereKey($bulkQr->pluck('id'))->where('commercial_status', CommercialStatus::Submitted)->whereNull('archived_at')->count() === 10, 'J: QR archival partially committed.');
    $closeResult = app(CloseStoreSession::class)->execute($bulk->cashier, $bulk->branch, $bulk->closePayload('1000.00', '0.00'));
    verifyPhase15($closeResult['qr_archived_count'] === 10, 'J: retry did not archive the complete QR set.');
    echo 'J PASS: a late failure rolled back all 10 QR archives; the retry archived all 10 atomically.'.PHP_EOL;

    /** K: concurrent Cash/Cashless/Split payments serialize and reconcile exactly. */
    $mixed = StoreCloseScenario::create('1000.00', '0.00');
    DB::beginTransaction();
    Branch::query()->whereKey($mixed->branch->id)->lockForUpdate()->sole();
    $payments = [];
    foreach (['cash', 'cashless', 'split', 'cash', 'cashless', 'split'] as $index => $method) {
        $payments[] = $startWorker('mixed_payments', $index, $mixed->cashier, $mixed->branch, ['pay_now', $mixed->product->id, $method]);
    }
    awaitPhase15Locks($observer, $schema.'_mixed_payments_', $payments);
    DB::commit();
    foreach ($payments as $process) {
        verifyPhase15($finish($process)['status'] === 200, 'K: a concurrent payment failed.');
    }
    Order::query()->where('store_session_id', $mixed->session->id)->get()->each(fn (Order $order) => $mixed->done($order));
    verifyPhase15($mixed->reconciliation()['expected'] === ['cash' => '1320.00', 'cashless' => '280.00'], 'K: concurrent payments reconciled inexactly: '.json_encode($mixed->reconciliation()['expected']));
    verifyPhase15($mixed->reconciliation()['split'] === ['cash' => '120.00', 'cashless' => '80.00', 'count' => 2], 'K: concurrent Split breakdown was wrong.');
    $closeResult = $finish($startWorker('mixed_close', 0, $mixed->cashier, $mixed->branch, $closeArgs($mixed, '1320.00', '280.00')));
    verifyPhase15($closeResult['status'] === 200 && $mixed->session->fresh()->cash_variance === '0.00' && $mixed->session->fresh()->cashless_variance === '0.00', 'K: exact close failed.');
    echo 'K PASS: six concurrent Cash/Cashless/Split payments reconciled and closed with zero variance.'.PHP_EOL;
    echo 'PASS: PostgreSQL Phase 15 schema and concurrency invariants.'.PHP_EOL;
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
        verifyPhase15($observer->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 0, 'Temporary schema was not removed.');
        echo 'CLEANUP PASS: removed isolated schema '.$schema.PHP_EOL;
    }
}
