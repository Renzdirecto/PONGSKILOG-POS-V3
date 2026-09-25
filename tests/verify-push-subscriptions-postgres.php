<?php

/**
 * Opt-in: DB_URL=null php tests/verify-push-subscriptions-postgres.php
 * PWA Phase 1 Web Push subscriptions on real PostgreSQL: migration rollback/reapply, constraints and indexes, and
 * independent processes enabling or disabling the same browser at once. Uses only loopback PostgreSQL and a random
 * pwa_* schema, then drops it. The normal development schema is never touched.
 */

use App\Actions\Notifications\RemovePushSubscription;
use App\Actions\Notifications\SavePushSubscription;
use App\Models\PushSubscription;
use App\Models\Role;
use App\Models\User;
use App\Support\PushDevice;
use Database\Factories\PushSubscriptionFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function pwaVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{endpoint: string, keys: array{p256dh: string, auth: string}, content_encoding: string} */
function pwaSubscription(string $endpoint, string $auth = PushSubscriptionFactory::BROWSER_AUTH_SECRET): array
{
    return [
        'endpoint' => $endpoint,
        'keys' => ['p256dh' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY, 'auth' => $auth],
        'content_encoding' => 'aes128gcm',
    ];
}

function pwaUser(string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->roles()->attach(Role::query()->where('name', 'cashier')->sole());

    return $user;
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
pwaVerify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        fwrite(STDERR, $current::class.': '.$current->getMessage().PHP_EOL);
    }
    exit(1);
});

$connection = config('database.connections.pgsql');
pwaVerify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
pwaVerify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
pwaVerify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$workerMode = $argv[1] ?? null;
$worker = in_array($workerMode, ['--enable', '--disable'], true);
$schema = $worker ? ($argv[2] ?? '') : 'pwa_'.bin2hex(random_bytes(8));
pwaVerify(preg_match('/\Apwa_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.pwa_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('pwa_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
pwaVerify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.$workerMode.'_'.($argv[5] ?? '0')]);
    $payload = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
    $user = User::query()->findOrFail($argv[3]);
    $result = match ($workerMode) {
        '--enable' => app(SavePushSubscription::class)->execute($user, $payload['device'], $payload['subscription'])->getKey(),
        '--disable' => app(RemovePushSubscription::class)->execute($user, $payload['device'], null),
    };
    echo json_encode([
        'status' => 'success',
        'result' => $result,
        'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        'level' => DB::transactionLevel(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/**
 * Starts the workers while the caller holds $hold inside an open transaction, waits until every worker is blocked
 * behind it, then ends that transaction ($commit false = roll back) so the workers race each other.
 *
 * @param  list<array{mode: string, actor: string, payload: array<string, mixed>}>  $jobs
 * @return list<array<string, mixed>>
 */
function pwaRace(string $schema, array $connection, object $observer, Closure $hold, bool $commit, array $jobs): array
{
    DB::beginTransaction();
    $hold();
    $processes = [];
    foreach ($jobs as $index => $job) {
        $process = new Process([
            PHP_BINARY, __FILE__, $job['mode'], $schema, $job['actor'],
            base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), (string) $index,
        ], dirname(__DIR__), [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
            'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
        ], timeout: 55);
        $process->start();
        $processes[] = $process;
    }
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'%'],
        );
        if (count($waiting) === count($jobs)) {
            break;
        }
        if (hrtime(true) >= $deadline) {
            $sessions = $observer->select(
                'SELECT application_name, state, wait_event_type, wait_event, left(query, 120) AS query FROM pg_stat_activity WHERE application_name LIKE ?',
                [$schema.'%'],
            );
            DB::rollBack();
            foreach ($processes as $process) {
                $process->stop(5);
            }
            throw new RuntimeException('Workers did not overlap at the lock boundary: '.json_encode($sessions).' '
                .implode(' | ', array_map(fn (Process $process): string => trim($process->getOutput().$process->getErrorOutput()), $processes)));
        }
        usleep(10_000);
    } while (true);
    $commit ? DB::commit() : DB::rollBack();

    $results = [];
    foreach ($processes as $process) {
        pwaVerify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    pwaVerify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers did not use independent connections.');
    pwaVerify(collect($results)->every(fn (array $result): bool => $result['level'] === 0), 'A worker left a transaction open.');

    return $results;
}

function pwaSqlState(Closure $write): ?string
{
    try {
        DB::transaction($write);
    } catch (QueryException $exception) {
        return (string) $exception->getCode();
    }

    return null;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;

    // A: fresh migrations, then the additive push migration rolls back and reapplies cleanly.
    pwaVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'A: fresh migration failed.');
    $latest = basename((string) collect(glob(database_path('migrations/*.php')) ?: [])->sort()->last());
    pwaVerify(str_ends_with($latest, 'create_push_subscriptions_table.php'), 'A: the push migration is not the latest.');
    pwaVerify(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0
        && ! DB::getSchemaBuilder()->hasTable('push_subscriptions'), 'A: rollback left the table behind.');
    pwaVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0
        && DB::getSchemaBuilder()->hasTable('push_subscriptions'), 'A: reapply failed.');
    echo 'CASE A PASS: fresh migration, rollback and reapply of push_subscriptions.'.PHP_EOL;

    // B: the database itself enforces one row per endpoint, the encoding list and the account foreign key.
    $indexes = collect(DB::select('SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = ? AND tablename = ?', [$schema, 'push_subscriptions']))
        ->pluck('indexdef', 'indexname');
    pwaVerify(str_contains((string) $indexes->get('push_subscriptions_endpoint_hash_unique'), 'UNIQUE INDEX')
        && $indexes->has('push_subscriptions_user_id_index')
        && $indexes->has('push_subscriptions_device_hash_index'), 'B: expected indexes are missing: '.$indexes->keys()->implode(', '));
    (new RbacSeeder)->run();
    $owner = pwaUser('owner@pwa.test');
    $row = fn (array $overrides = []): array => [
        'user_id' => $owner->id, 'endpoint_hash' => str_repeat('a', 64), 'device_hash' => str_repeat('b', 64),
        'endpoint' => 'x', 'public_key' => 'x', 'auth_token' => 'x', 'content_encoding' => 'aes128gcm',
        'created_at' => now(), 'updated_at' => now(), ...$overrides,
    ];
    DB::table('push_subscriptions')->insert($row());
    pwaVerify(pwaSqlState(fn () => DB::table('push_subscriptions')->insert($row())) === '23505', 'B: a duplicate endpoint was accepted.');
    pwaVerify(pwaSqlState(fn () => DB::table('push_subscriptions')->insert($row(['endpoint_hash' => str_repeat('c', 64), 'content_encoding' => 'gzip']))) === '23514', 'B: an unknown encoding was accepted.');
    pwaVerify(pwaSqlState(fn () => DB::table('push_subscriptions')->insert($row(['endpoint_hash' => str_repeat('d', 64), 'user_id' => 999999]))) === '23503', 'B: an unknown account was accepted.');
    DB::table('push_subscriptions')->delete();
    echo 'CASE B PASS: unique endpoint_hash, content_encoding CHECK, user foreign key and lookup indexes.'.PHP_EOL;

    // C: two accounts enable the same browser endpoint at the same moment: both succeed, one row remains.
    $first = pwaUser('first@pwa.test');
    $second = pwaUser('second@pwa.test');
    $endpoint = 'https://fcm.googleapis.com/fcm/send/race-endpoint';
    $device = PushDevice::newId();
    $results = pwaRace($schema, $connection, $observer, fn () => DB::table('push_subscriptions')->insert($row([
        'user_id' => $owner->id, 'endpoint_hash' => PushSubscription::hashEndpoint($endpoint),
    ])), false, [
        ['mode' => '--enable', 'actor' => (string) $first->id, 'payload' => ['device' => $device, 'subscription' => pwaSubscription($endpoint)]],
        ['mode' => '--enable', 'actor' => (string) $second->id, 'payload' => ['device' => $device, 'subscription' => pwaSubscription($endpoint)]],
    ]);
    $stored = PushSubscription::query()->get();
    pwaVerify(collect($results)->every(fn (array $result): bool => $result['status'] === 'success')
        && $stored->count() === 1
        && in_array($stored->sole()->user_id, [$first->id, $second->id], true)
        && $stored->sole()->endpoint === $endpoint, 'C: racing enables did not converge on one row.');
    echo 'CASE C PASS: racing enables of one endpoint converged on one row (owner '.$stored->sole()->user_id.'), no error, no open transaction.'.PHP_EOL;

    // D: disable races a re-enable with rotated keys of the same browser: both succeed, one consistent state remains.
    // (Re-enabling with identical material writes nothing, so the rotation is what makes the enable contend.)
    $holder = $stored->sole();
    $rotatedAuth = rtrim(strtr(base64_encode(random_bytes(16)), '+/', '-_'), '=');
    $results = pwaRace($schema, $connection, $observer, fn () => PushSubscription::query()->whereKey($holder->id)->lockForUpdate()->sole(), true, [
        ['mode' => '--disable', 'actor' => (string) $holder->user_id, 'payload' => ['device' => $device]],
        ['mode' => '--enable', 'actor' => (string) $holder->user_id, 'payload' => ['device' => $device, 'subscription' => pwaSubscription($endpoint, $rotatedAuth)]],
    ]);
    $count = PushSubscription::query()->count();
    pwaVerify(collect($results)->every(fn (array $result): bool => $result['status'] === 'success') && in_array($count, [0, 1], true)
        && PushSubscription::query()->where('endpoint_hash', PushSubscription::hashEndpoint($endpoint))->count() === $count, 'D: disable vs enable left an inconsistent state.');
    echo 'CASE D PASS: disable vs re-enable serialized without deadlock ('.$count.' subscription left).'.PHP_EOL;

    echo 'PWA PUSH SUBSCRIPTIONS POSTGRESQL VERIFICATION PASSED'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        echo 'DROPPED '.$schema.PHP_EOL;
    }
}
