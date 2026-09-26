<?php

/**
 * Opt-in: DB_URL=null php tests/verify-customer-experience-postgres.php
 * Phase 19.6 Customer Experience on real PostgreSQL: migration rollback/reapply and constraints, independent processes
 * buzzing the same Ready Take Out order at once (5-second cooldown and idempotency under a real row lock), and two
 * screens paired with one POS station at the same instant (unique Branch + station index, one retry releases the
 * previous screen). Uses only loopback PostgreSQL and a random cx_* schema, then drops it. The normal development
 * schema is never touched.
 */

use App\Actions\CustomerScreens\PairCustomerScreen;
use App\Actions\Pickup\BuzzPickupCustomer;
use App\Enums\KitchenStatus;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\KitchenTicket;
use App\Models\Order;
use App\Models\OrderPickupToken;
use App\Models\PickupPushSubscription;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\CustomerScreens;
use App\Support\PickupPushGateway;
use App\Support\PickupTokens;
use Database\Factories\PushSubscriptionFactory;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function cxVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
cxVerify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    for ($current = $exception; $current !== null; $current = $current->getPrevious()) {
        fwrite(STDERR, $current::class.': '.$current->getMessage().PHP_EOL);
    }
    exit(1);
});

$connection = config('database.connections.pgsql');
cxVerify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
cxVerify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
cxVerify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$workerMode = $argv[1] ?? null;
$worker = in_array($workerMode, ['--buzz', '--pair'], true);
$schema = $worker ? ($argv[2] ?? '') : 'cx_'.bin2hex(random_bytes(8));
cxVerify(preg_match('/\Acx_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.cx_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
    'services.webpush' => ['subject' => 'mailto:cx@example.test', 'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY, 'private_key' => 'harness-only'],
]);
/** Workers never reach a push service: delivery is recorded as accepted. */
app()->instance(PickupPushGateway::class, new class implements PickupPushGateway
{
    public function deliverPickup(PickupPushSubscription $subscription, string $payload, string $topic): ?int
    {
        return 201;
    }
});
DB::purge('pgsql');
$observer = DB::connection('cx_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
cxVerify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.$workerMode.'_'.($argv[5] ?? '0')]);
    $payload = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
    $user = User::query()->findOrFail($argv[3]);
    try {
        $result = match ($workerMode) {
            '--buzz' => app(BuzzPickupCustomer::class)->execute($user, Branch::query()->findOrFail($payload['branch']), Order::query()->findOrFail($payload['order']), $payload['key']),
            '--pair' => ['screen' => app(PairCustomerScreen::class)->execute($user, Branch::query()->findOrFail($payload['branch']), $payload['station'], $payload['code'])->id],
        };
        $status = 'success';
    } catch (HttpExceptionInterface $exception) {
        [$status, $result] = ['rejected', ['code' => $exception->getStatusCode()]];
    }
    echo json_encode([
        'status' => $status,
        'result' => $result,
        'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid,
        'level' => DB::transactionLevel(),
    ], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/**
 * Starts the workers while the caller holds $hold inside an open transaction, waits until every worker is blocked
 * behind it, then commits so the workers race each other.
 *
 * @param  list<array{mode: string, actor: string, payload: array<string, mixed>}>  $jobs
 * @return list<array<string, mixed>>
 */
function cxRace(string $schema, array $connection, object $observer, Closure $hold, array $jobs): array
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
            DB::rollBack();
            foreach ($processes as $process) {
                $process->stop(5);
            }
            throw new RuntimeException('Workers did not overlap at the lock boundary: '
                .implode(' | ', array_map(fn (Process $process): string => trim($process->getOutput().$process->getErrorOutput()), $processes)));
        }
        usleep(10_000);
    } while (true);
    DB::commit();

    $results = [];
    foreach ($processes as $process) {
        cxVerify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    cxVerify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers did not use independent connections.');
    cxVerify(collect($results)->every(fn (array $result): bool => $result['level'] === 0), 'A worker left a transaction open.');

    return $results;
}

function cxStaff(Branch $branch, string $role, string $email): User
{
    $user = User::factory()->create(['email' => $email]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    $user->branches()->attach($branch, ['is_active' => true]);

    return $user;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;

    // A: fresh migrations, then the additive Phase 19.6 migration rolls back and reapplies cleanly with its constraints.
    $tables = ['customer_screens', 'customer_screen_media', 'order_pickup_tokens', 'pickup_push_subscriptions'];
    cxVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'A: fresh migration failed.');
    $latest = basename((string) collect(glob(database_path('migrations/*.php')) ?: [])->sort()->last());
    cxVerify(str_ends_with($latest, 'create_customer_experience_tables.php'), 'A: the Phase 19.6 migration is not the latest.');
    cxVerify(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0
        && collect($tables)->every(fn (string $table): bool => ! DB::getSchemaBuilder()->hasTable($table)), 'A: rollback left a table behind.');
    cxVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0
        && collect($tables)->every(fn (string $table): bool => DB::getSchemaBuilder()->hasTable($table)), 'A: reapply failed.');
    $indexes = collect(DB::select('SELECT indexname FROM pg_indexes WHERE schemaname = ?', [$schema]))->pluck('indexname');
    foreach (['customer_screens_branch_id_station_hash_unique', 'customer_screens_token_hash_unique', 'customer_screens_pairing_code_hash_unique',
        'order_pickup_tokens_order_id_unique', 'order_pickup_tokens_token_hash_unique', 'order_pickup_tokens_branch_id_expires_at_index',
        'pickup_push_subscriptions_order_pickup_token_id_unique', 'customer_screen_media_branch_id_is_active_sort_order_index'] as $index) {
        cxVerify($indexes->contains($index), 'A: missing index '.$index);
    }
    $invalidMode = null;
    try {
        DB::transaction(fn () => DB::table('customer_screens')->insert(['id' => (string) Str::uuid(), 'token_hash' => str_repeat('a', 64), 'channel_key' => str_repeat('b', 40), 'mode' => 'menu_and_display']));
    } catch (QueryException $exception) {
        $invalidMode = (string) $exception->getCode();
    }
    cxVerify($invalidMode === '23514', 'A: a mode outside ads/menu/customer_display was accepted.');
    echo 'CASE A PASS: fresh migration, rollback and reapply; unique/lookup indexes and the mode CHECK exist.'.PHP_EOL;

    (new RbacSeeder)->run();
    $branch = Branch::factory()->create(['code' => 'CXPG']);
    $session = StoreSession::factory()->for($branch)->create();
    $cashiers = collect(range(1, 4))->map(fn (int $number): User => cxStaff($branch, 'cashier', "cashier{$number}@cx.test"));
    $order = Order::factory()->for($branch)->for($session)->create([
        'order_type' => 'take_out', 'order_number' => '24', 'commercial_status' => 'active', 'payment_status' => 'paid',
        'payment_term' => 'immediate', 'kitchen_status' => KitchenStatus::Ready, 'committed_at' => now(), 'ready_at' => now(),
    ]);
    KitchenTicket::factory()->for($branch)->for($order)->create(['status' => KitchenStatus::Ready]);
    $pickup = app(PickupTokens::class)->issue($order);
    cxVerify($pickup !== null, 'B: the Take Out order got no pickup token.');
    PickupPushSubscription::query()->create([
        'order_pickup_token_id' => $pickup->id, 'endpoint_hash' => hash('sha256', 'cx-endpoint'),
        'endpoint' => 'https://fcm.googleapis.com/fcm/send/cx-endpoint', 'public_key' => PushSubscriptionFactory::BROWSER_PUBLIC_KEY,
        'auth_token' => PushSubscriptionFactory::BROWSER_AUTH_SECRET, 'content_encoding' => 'aes128gcm',
    ]);
    $before = $order->fresh()->only(['kitchen_status', 'commercial_status', 'version']);

    // B: four cashiers buzz the same order at the same instant: exactly one Buzz is accepted, the rest hit the cooldown.
    $results = cxRace($schema, $connection, $observer, fn () => OrderPickupToken::query()->whereKey($pickup->id)->lockForUpdate()->sole(),
        $cashiers->values()->map(fn (User $cashier): array => ['mode' => '--buzz', 'actor' => (string) $cashier->id,
            'payload' => ['branch' => $branch->id, 'order' => $order->id, 'key' => (string) Str::uuid()]])->all());
    $accepted = collect($results)->where('status', 'success');
    cxVerify($accepted->count() === 1 && collect($results)->where('status', 'rejected')->every(fn (array $result): bool => $result['result']['code'] === 429)
        && $pickup->fresh()->buzz_count === 1, 'B: concurrent Buzzes were not serialized: '.json_encode($results));
    echo 'CASE B PASS: 4 concurrent Buzzes → 1 accepted, 3 rejected by the 5-second cooldown, buzz_count 1.'.PHP_EOL;

    // C: after the cooldown, three requests replaying one idempotency key at once send exactly one more Buzz.
    DB::table('order_pickup_tokens')->where('id', $pickup->id)->update(['last_buzzed_at' => now()->subSeconds(10)]);
    $key = (string) Str::uuid();
    $results = cxRace($schema, $connection, $observer, fn () => OrderPickupToken::query()->whereKey($pickup->id)->lockForUpdate()->sole(),
        $cashiers->take(3)->values()->map(fn (User $cashier): array => ['mode' => '--buzz', 'actor' => (string) $cashier->id,
            'payload' => ['branch' => $branch->id, 'order' => $order->id, 'key' => $key]])->all());
    cxVerify(collect($results)->every(fn (array $result): bool => $result['status'] === 'success')
        && collect($results)->where('result.replayed', false)->count() === 1
        && $pickup->fresh()->buzz_count === 2
        && $order->fresh()->only(array_keys($before)) == $before, 'C: a replayed Buzz key was sent twice or changed the order: '.json_encode($results));
    echo 'CASE C PASS: 3 concurrent replays of one key → 1 Buzz sent, 2 replays, buzz_count 2, order unchanged.'.PHP_EOL;

    // D: a second screen is paired with a station while the first pairing of that station is still committing.
    $station = (string) Str::uuid();
    $screens = app(CustomerScreens::class);
    $screen = fn (string $code): CustomerScreen => CustomerScreen::query()->create([
        'token_hash' => hash('sha256', Str::random(64)), 'channel_key' => Str::random(40),
        'pairing_code_hash' => $screens->codeHash($code), 'pairing_code_expires_at' => now()->addMinutes(5),
    ]);
    $first = $screen('AAAAAA');
    $second = $screen('BBBBBB');
    $results = cxRace($schema, $connection, $observer, fn () => $first->update([
        'branch_id' => $branch->id, 'station_hash' => $screens->stationHash($station), 'paired_at' => now(), 'pairing_code_hash' => null,
    ]), [['mode' => '--pair', 'actor' => (string) $cashiers->first()->id, 'payload' => ['branch' => $branch->id, 'station' => $station, 'code' => 'BBBBBB']]]);
    cxVerify($results[0]['status'] === 'success' && $results[0]['result']['screen'] === $second->id
        && $second->fresh()->isPaired() && ! $first->fresh()->isPaired()
        && CustomerScreen::query()->where('branch_id', $branch->id)->count() === 1, 'D: the racing pairing did not release the previous screen: '.json_encode($results));
    echo 'CASE D PASS: pairing racing an uncommitted pairing of the same station hit the unique index, retried once and released the previous screen.'.PHP_EOL;

    echo 'PHASE 19.6 CUSTOMER EXPERIENCE POSTGRESQL VERIFICATION PASSED'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        echo 'DROPPED '.$schema.PHP_EOL;
    }
}
