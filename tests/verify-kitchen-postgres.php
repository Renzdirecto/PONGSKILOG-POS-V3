<?php

/**
 * Opt-in real PostgreSQL verification: DB_URL=null php tests/verify-kitchen-postgres.php.
 * Creates and removes only a random phase8_* schema. Independent workers exercise
 * the real transition action against committed fixtures and overlapping row locks.
 */

use App\Actions\Orders\TransitionKitchenOrder;
use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
verify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
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
$schema = $worker ? ($argv[2] ?? '') : 'phase8_'.bin2hex(random_bytes(8));
verify(preg_match('/\Aphase8_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');

config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase8_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase8_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host, inet_server_port() AS port');
verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL must report a loopback address.');

if ($worker) {
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Worker schema isolation failed.');
    DB::statement("SET lock_timeout = '15s'");
    DB::statement("SET statement_timeout = '20s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_worker_'.$argv[6]]);

    $order = app(TransitionKitchenOrder::class)->execute(
        User::findOrFail($argv[3]),
        Branch::findOrFail($argv[4]),
        Order::findOrFail($argv[5]),
        KitchenStatus::Ready,
    );

    verify(DB::transactionLevel() === 0, 'Worker left a transaction open.');
    echo json_encode([
        'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        'status' => $order->kitchen_status->value,
        'ticket_status' => $order->kitchenTicket->status->value,
        'version' => $order->version,
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$processes = [];
$createdSchema = false;

try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    verify(DB::selectOne('SELECT current_schema() AS schema')->schema === $schema, 'Parent schema isolation failed.');
    verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh isolated migration failed.');
    (new RbacSeeder)->run();

    $branch = Branch::factory()->create();
    $session = StoreSession::factory()->for($branch)->create();
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', 'kitchen_staff')->sole());
    $user->branches()->attach($branch, ['is_active' => true]);
    $order = Order::factory()->for($branch)->for($session)->create([
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'payment_term' => 'immediate',
        'kitchen_status' => KitchenStatus::Kitchen,
        'committed_at' => now(),
        'version' => 7,
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => KitchenStatus::Kitchen]);

    $environment = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'pgsql',
        'DB_URL' => 'null',
        'DB_HOST' => $connection['host'],
        'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_SSLMODE' => $connection['sslmode'],
    ];

    DB::beginTransaction();
    StoreSession::query()->whereKey($session->id)->lockForUpdate()->sole();

    foreach ([1, 2] as $workerNumber) {
        $process = new Process([
            PHP_BINARY,
            __FILE__,
            '--worker',
            $schema,
            (string) $user->id,
            $branch->id,
            $order->id,
            (string) $workerNumber,
        ], dirname(__DIR__), $environment, timeout: 30);
        $processes[] = $process;
        $process->start();
    }

    $deadline = hrtime(true) + 10_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity
             WHERE datname = current_database()
             AND application_name LIKE ?
             AND state = 'active'
             AND wait_event_type = 'Lock'
             AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'_worker_%'],
        );

        if (count($waiting) === 2) {
            break;
        }

        foreach ($processes as $process) {
            verify($process->isRunning(), 'Worker exited before lock overlap: '.$process->getErrorOutput().$process->getOutput());
        }

        verify(hrtime(true) < $deadline, 'Workers did not overlap at the Store Session lock.');
        usleep(10_000);
    } while (true);

    DB::commit();

    $results = [];
    foreach ($processes as $process) {
        verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }

    $order->refresh();
    $order->load('kitchenTicket');
    verify(count(array_unique(array_column($results, 'pid'))) === 2, 'Workers must use independent connections.');
    verify(collect($results)->every(fn (array $result): bool => $result['status'] === 'ready' && $result['ticket_status'] === 'ready'), 'Workers did not resolve the same authoritative status.');
    verify($order->kitchen_status === KitchenStatus::Ready, 'Order did not reach Ready.');
    verify($order->kitchenTicket->status === KitchenStatus::Ready, 'Kitchen ticket diverged from the order.');
    verify($order->version === 8, 'Duplicate transition incremented the version more than once.');
    verify(DB::transactionLevel() === 0, 'Parent transaction did not close.');

    echo 'PASS: PostgreSQL row locking serialized two overlapping Ready transitions; both records agree and version advanced once.'.PHP_EOL;
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
        $observer->statement('DROP SCHEMA IF EXISTS "'.$schema.'" CASCADE');
    }
}
