<?php

/**
 * Opt-in real PostgreSQL verification: php tests/verify-store-session-postgres.php
 * Supply local DB_* settings with DB_URL=null. Creates and removes only a random
 * phase2e_* schema; never refreshes the configured database or its public schema.
 * Kept outside Pest's RefreshDatabase suite so workers see committed fixtures.
 */

use App\Actions\StoreSessions\OpenStoreSession;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function rejectsSqlState(Closure $write, string $state): void
{
    try {
        DB::transaction($write);
    } catch (QueryException $exception) {
        verify(($exception->errorInfo[0] ?? null) === $state, 'Unexpected constraint failure: '.$exception->getMessage());

        return;
    }

    throw new RuntimeException('Expected SQLSTATE '.$state.' was not raised.');
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
$schema = $worker ? ($argv[2] ?? '') : 'phase2e_'.bin2hex(random_bytes(8));
verify(preg_match('/\Aphase2e_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase2e_admin' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$admin = DB::connection('phase2e_admin');
$identity = $admin->selectOne('SELECT current_database() AS database, host(inet_server_addr()) AS host, inet_server_port() AS port');
verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server did not report a loopback address.');

if ($worker) {
    verify($admin->selectOne('SELECT count(*) AS count FROM pg_namespace WHERE nspname = ?', [$schema])->count === 1, 'Missing isolated schema.');
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '20s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_'.$argv[3]]);
    $user = User::query()->findOrFail($argv[3]);
    $branch = Branch::query()->findOrFail($argv[4]);
    $session = app(OpenStoreSession::class)->execute($user, $branch, $argv[5], $argv[6]);
    $created = $session->wasRecentlyCreated;
    $session->refresh();
    verify(DB::transactionLevel() === 0, 'Worker left an open transaction.');
    verify(StoreSession::query()->whereKey($session->id)->exists(), 'Worker connection is unusable after opening.');
    echo json_encode([
        'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        'caller' => $user->id,
        'created' => $created,
        'attributes' => $session->getAttributes(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$processes = [];
$createdSchema = false;

try {
    $admin->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Schema isolation failed.');
    echo 'LOCAL TARGET '.json_encode($identity, JSON_THROW_ON_ERROR).' schema='.$schema.PHP_EOL;
    verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');
    verify(Artisan::call('db:seed', ['--class' => RbacSeeder::class, '--force' => true, '--no-interaction' => true]) === 0, 'RBAC seed failed.');
    verify(Role::query()->count() === 5 && StoreSession::query()->count() === 0, 'RBAC smoke state is incorrect.');

    $index = DB::selectOne(
        'SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = ?',
        [$schema, 'store_sessions_one_open_per_branch_unique'],
    );
    verify($index !== null && str_contains($index->indexdef, 'UNIQUE INDEX') && str_contains($index->indexdef, "WHERE ((status)::text = 'open'::text)"), 'Partial unique index is missing or incorrect.');
    echo 'PARTIAL INDEX '.$index->indexdef.PHP_EOL;
    $columns = collect(DB::select(
        'SELECT column_name, data_type, numeric_precision, numeric_scale FROM information_schema.columns WHERE table_schema = ? AND table_name = ?',
        [$schema, 'store_sessions'],
    ))->keyBy('column_name');
    verify($columns['id']->data_type === 'uuid', 'StoreSession ID must be UUID.');
    foreach (['opening_cash_amount', 'opening_cashless_amount', 'closing_cash_amount', 'closing_cashless_amount', 'expected_cash_amount', 'expected_cashless_amount', 'cash_variance', 'cashless_variance'] as $column) {
        verify($columns[$column]->data_type === 'numeric' && $columns[$column]->numeric_precision === 14 && $columns[$column]->numeric_scale === 2, $column.' must be numeric(14,2).');
    }

    $branch = Branch::factory()->create();
    $cashiers = User::factory()->count(2)->create();
    $role = Role::query()->where('name', 'cashier')->sole();
    $amounts = [['111.11', '222.22'], ['333.33', '444.44']];
    foreach ($cashiers as $cashier) {
        $cashier->roles()->attach($role);
        $cashier->branches()->attach($branch, ['is_active' => true]);
    }

    /** Hold the branch until PostgreSQL observes BOTH real action calls waiting on its lock. */
    DB::beginTransaction();
    Branch::query()->whereKey($branch->id)->lockForUpdate()->sole();
    foreach ($cashiers as $position => $cashier) {
        $process = new Process([
            PHP_BINARY, __FILE__, '--worker', $schema, (string) $cashier->id,
            $branch->id, ...$amounts[$position],
        ], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 25);
        $processes[] = $process;
        $process->start();
    }

    $deadline = hrtime(true) + 10_000_000_000;
    do {
        $waiting = $admin->select(
            "SELECT pid, wait_event_type, pg_blocking_pids(pid) AS blockers FROM pg_stat_activity
             WHERE datname = current_database() AND application_name IN (?, ?)
             AND state = 'active' AND wait_event_type = 'Lock'
             AND query LIKE 'select%branches%for update' AND cardinality(pg_blocking_pids(pid)) > 0",
            $cashiers->map(fn (User $cashier): string => $schema.'_'.$cashier->id)->all(),
        );
        if (count($waiting) === 2) {
            break;
        }
        foreach ($processes as $process) {
            verify($process->isRunning(), 'Worker exited before overlap: '.$process->getErrorOutput().$process->getOutput());
        }
        verify(hrtime(true) < $deadline, 'Timed out waiting for two overlapping branch-lock requests.');
        usleep(10_000);
    } while (true);
    echo 'OVERLAPPING TRANSACTIONS '.json_encode($waiting, JSON_THROW_ON_ERROR).PHP_EOL;
    DB::commit();

    $results = [];
    foreach ($processes as $process) {
        verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    verify($results[0]['pid'] !== $results[1]['pid'], 'Workers must use separate PostgreSQL connections.');
    verify($results[0]['attributes'] === $results[1]['attributes'], 'Callers did not resolve identical persisted opening data.');
    $winners = array_values(array_filter($results, fn (array $result): bool => $result['created']));
    verify(count($winners) === 1, 'Exactly one caller must create the session.');
    $session = StoreSession::query()->sole();
    $winner = $winners[0];
    $winnerPosition = $cashiers->search(fn (User $cashier): bool => $cashier->id === $winner['caller']);
    verify($session->status === StoreSessionStatus::Open && $session->opened_by_user_id === $winner['caller'], 'Winning opener was not preserved.');
    verify([$session->opening_cash_amount, $session->opening_cashless_amount] === $amounts[$winnerPosition], 'Winning balances were overwritten.');
    verify($session->getAttributes() === $winner['attributes'], 'Winning timestamps or other opening fields were changed.');
    echo 'CONCURRENCY PASS '.json_encode([
        'session_id' => $session->id, 'open_count' => StoreSession::query()->where('status', 'open')->count(),
        'winner_user_id' => $winner['caller'], 'cash' => $session->opening_cash_amount,
        'cashless' => $session->opening_cashless_amount, 'opened_at' => $session->opened_at->toISOString(),
        'callers' => array_column($results, 'caller'), 'worker_pids' => array_column($results, 'pid'),
    ], JSON_THROW_ON_ERROR).PHP_EOL;

    $duplicate = [...$session->getAttributes(), 'id' => (string) Str::uuid()];
    rejectsSqlState(fn (): bool => DB::table('store_sessions')->insert($duplicate), '23505');
    foreach (['opening_cash_amount', 'opening_cashless_amount'] as $column) {
        rejectsSqlState(fn (): int => DB::table('store_sessions')->where('id', $session->id)->update([$column => '-0.01']), '23514');
    }
    rejectsSqlState(fn (): int => DB::table('store_sessions')->where('id', $session->id)->update(['status' => 'pending']), '23514');
    foreach (['branch_id' => (string) Str::uuid(), 'opened_by_user_id' => 999999, 'closed_by_user_id' => 999999] as $column => $missingId) {
        rejectsSqlState(fn (): int => DB::table('store_sessions')->where('id', $session->id)->update([$column => $missingId]), '23503');
    }
    rejectsSqlState(fn (): ?bool => $branch->delete(), '23001');
    rejectsSqlState(fn (): ?bool => $session->openedBy->delete(), '23001');
    StoreSession::factory()->closed()->for($branch)->count(2)->create();
    StoreSession::factory()->create();
    verify($branch->storeSessions()->where('status', 'open')->count() === 1, 'One-open-per-branch invariant failed.');
    verify($branch->storeSessions()->where('status', 'closed')->count() === 2, 'Closed history was restricted.');
    verify(StoreSession::query()->where('status', 'open')->count() === 2, 'Independent branches cannot each open.');
    verify(DB::transactionLevel() === 0, 'Constraint checks left a broken transaction.');
    echo 'PASS: PostgreSQL concurrency, exact money schema, constraints, retained history, fresh migration and RBAC seed.'.PHP_EOL;
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
        $admin->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        echo 'CLEANUP: removed isolated schema '.$schema.PHP_EOL;
    }
}
