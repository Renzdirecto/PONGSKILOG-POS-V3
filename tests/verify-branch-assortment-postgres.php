<?php

/**
 * Opt-in: DB_URL=null php tests/verify-branch-assortment-postgres.php
 * Phase 18 Manual QA pass #2 Branch assortment copy on real PostgreSQL: racing copies into one Branch end with exactly
 * one configuration row per Product, never a deadlock, never a duplicate Product, never copied stock. Uses only
 * loopback PostgreSQL and a random assort_* schema, then drops it. The normal development schema is never touched.
 */

use App\Actions\Catalog\ConfigureBranchAssortment;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function assortVerify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
assortVerify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
assortVerify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
assortVerify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
assortVerify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$worker = ($argv[1] ?? null) === '--copy';
$schema = $worker ? ($argv[2] ?? '') : 'assort_'.bin2hex(random_bytes(8));
assortVerify(preg_match('/\Aassort_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.assort_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('assort_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
assortVerify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.'_copy_'.($argv[5] ?? '0')]);
    $payload = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
    $result = app(ConfigureBranchAssortment::class)->copy(
        User::query()->findOrFail($argv[3]),
        Branch::query()->findOrFail($payload['source']),
        Branch::query()->findOrFail($payload['destination']),
        $payload['products'],
        $payload['overwrite'],
    );
    echo json_encode([...$result, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    assortVerify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migration failed.');
    app(RbacSeeder::class)->run();

    $main = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $role = Role::query()->forceCreate(['name' => 'custom_1', 'label' => 'Branch Manager', 'is_system' => false, 'scope' => 'branch']);
    $role->permissions()->sync(DB::table('permissions')->where('name', 'products.manage')->pluck('id')->all());
    $manager = User::factory()->create();
    $manager->roles()->attach($role);
    $manager->branches()->attach([$main->id => ['is_active' => true], $qave->id => ['is_active' => true]]);
    $products = Product::factory()->count(12)->create();
    foreach ($products as $index => $product) {
        BranchProduct::factory()->for($qave)->for($product)->create(['price_override' => (string) (50 + $index).'.00', 'tracks_inventory' => $index % 2 === 0]);
        BranchInventory::factory()->for($qave)->for($product)->create(['on_hand' => 20]);
    }
    /** One Product is already configured at MAIN: a skip-mode copy must keep it. */
    BranchProduct::factory()->for($main)->for($products[0])->create(['price_override' => '1.00']);
    $productCount = Product::query()->count();
    $ids = $products->pluck('id')->all();
    $environment = [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
    ];

    /** A POS commit holds MAIN FOR UPDATE; three copies (two skip, one overwrite, reversed selections) queue behind it. */
    DB::beginTransaction();
    Branch::query()->whereKey($main->id)->lockForUpdate()->firstOrFail();
    $processes = [];
    foreach ([[$ids, false], [array_reverse($ids), false], [$ids, true]] as $index => [$selection, $overwrite]) {
        $process = new Process([
            PHP_BINARY, __FILE__, '--copy', $schema, (string) $manager->id,
            base64_encode(json_encode(['source' => $qave->id, 'destination' => $main->id, 'products' => $selection, 'overwrite' => $overwrite], JSON_THROW_ON_ERROR)),
            (string) $index,
        ], dirname(__DIR__), $environment, timeout: 55);
        $process->start();
        $processes[] = $process;
    }
    $deadline = hrtime(true) + 20_000_000_000;
    do {
        $waiting = $observer->select(
            "SELECT pid FROM pg_stat_activity WHERE datname = current_database() AND application_name LIKE ? AND state = 'active' AND wait_event_type = 'Lock' AND cardinality(pg_blocking_pids(pid)) > 0",
            [$schema.'%'],
        );
        if (count($waiting) === count($processes)) {
            break;
        }
        assortVerify(hrtime(true) < $deadline, 'Copies did not overlap at the Branch lock.');
        usleep(10_000);
    } while (true);
    DB::commit();

    $results = [];
    foreach ($processes as $process) {
        assortVerify($process->wait() === 0, 'Copy worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    assortVerify(count(array_unique(array_column($results, 'pid'))) === 3, 'Workers did not use independent connections.');

    $rows = BranchProduct::query()->where('branch_id', $main->id)->get();
    assortVerify($rows->count() === 12 && $rows->pluck('product_id')->unique()->count() === 12, 'Racing copies must leave exactly one configuration per Product.');
    assortVerify(array_sum(array_column($results, 'copied')) === 11, 'Exactly one copy may create each new configuration.');
    foreach ($results as $result) {
        assortVerify($result['copied'] + $result['overwritten'] + $result['skipped'] === 12, 'Every selected Product must be accounted for once per copy.');
    }
    assortVerify($results[2]['skipped'] === 0, 'The overwrite copy never skips.');
    assortVerify($rows->firstWhere('product_id', $products[0]->id)->price_override === '50.00', 'The explicit overwrite must replace the existing MAIN setting.');
    assortVerify(BranchInventory::query()->where('branch_id', $main->id)->doesntExist(), 'Stock must never be copied.');
    assortVerify(Product::query()->count() === $productCount, 'Products must never be duplicated.');
    assortVerify(DB::table('audit_logs')->where('action', 'branch_products.copied')->count() === count(array_filter($results, fn (array $result): bool => $result['copied'] + $result['overwritten'] > 0)), 'Each changing copy is audited once.');

    echo 'Branch assortment PostgreSQL verification passed: racing copies left 12 unique MAIN configurations, 11 created once, overwrite applied, no stock copied, no deadlock.'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
    }
    assortVerify($observer->selectOne('SELECT COUNT(*) AS total FROM pg_namespace WHERE nspname = ?', [$schema])->total === 0, 'Isolated schema was not dropped.');
}
