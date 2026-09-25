<?php

/**
 * Opt-in: DB_URL=null php tests/verify-access-admin-postgres.php
 * Phase 18 Access Control, Staff administration and Notifications on real PostgreSQL. Uses only loopback PostgreSQL
 * and a random phase18_* schema, then drops it. The normal development schema is never touched.
 */

use App\Actions\AccessControl\ArchiveCustomRole;
use App\Actions\AccessControl\CreateCustomRole;
use App\Actions\AccessControl\UpdateCustomRole;
use App\Actions\AccessControl\UpdateRolePermissions;
use App\Actions\Inventory\ApplyInventoryMovement;
use App\Actions\Staff\UpdateStaffAccount;
use App\Enums\InventoryMovementType;
use App\Enums\PermissionOverrideEffect;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\BranchProduct;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Notifications\AdminAlert;
use App\Support\PermissionCatalog;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require dirname(__DIR__).'/vendor/autoload.php';

function phase18Verify(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function phase18User(string $role, ?Branch $branch = null, bool $active = true): User
{
    $user = User::factory()->create(['is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @return list<string> */
function phase18Baseline(string $role): array
{
    return PermissionCatalog::ordered(Role::query()->where('name', $role)->sole()->permissions()->pluck('permissions.name')->all());
}

function phase18ActiveSuperAdmins(): int
{
    return User::query()->where('is_active', true)->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))->count();
}

/** @return array<string, mixed> */
function phase18Edit(User $user, array $overrides = []): array
{
    return [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $user->roles()->value('name'),
        'branch_ids' => $user->branches()->pluck('branches.id')->all(),
        'is_active' => $user->is_active,
        ...$overrides,
    ];
}

$app = require dirname(__DIR__).'/bootstrap/app.php';
phase18Verify(! $app->configurationIsCached(), 'Cached configuration is not allowed.');
$app->make(Kernel::class)->bootstrap();
set_exception_handler(function (Throwable $exception): never {
    fwrite(STDERR, $exception::class.': '.$exception->getMessage().PHP_EOL);
    exit(1);
});

$connection = config('database.connections.pgsql');
phase18Verify(app()->environment(['local', 'testing']), 'Only local/testing environments are allowed.');
phase18Verify(config('database.default') === 'pgsql' && empty($connection['url']), 'Explicit pgsql settings and DB_URL=null are required.');
phase18Verify(in_array($connection['host'], ['127.0.0.1', '::1'], true), 'Only loopback PostgreSQL is allowed.');
$workerMode = $argv[1] ?? null;
$worker = in_array($workerMode, ['--staff', '--sell', '--role', '--custom-create', '--custom-update', '--custom-archive'], true);
$schema = $worker ? ($argv[2] ?? '') : 'phase18_'.bin2hex(random_bytes(8));
phase18Verify(preg_match('/\Aphase18_[a-f0-9]{16}\z/', $schema) === 1, 'Invalid isolated schema name.');
config([
    'database.connections.pgsql.search_path' => $schema,
    'database.connections.phase18_observer' => [...$connection, 'search_path' => 'pg_catalog'],
    'cache.default' => 'array',
    'session.driver' => 'array',
    'queue.default' => 'sync',
    'broadcasting.default' => 'null',
    'hashing.bcrypt.rounds' => 4,
]);
DB::purge('pgsql');
$observer = DB::connection('phase18_observer');
$identity = $observer->selectOne('SELECT host(inet_server_addr()) AS host');
phase18Verify(in_array($identity->host, ['127.0.0.1', '::1'], true), 'PostgreSQL server is not loopback.');

if ($worker) {
    DB::statement("SET lock_timeout = '30s'");
    DB::statement("SET statement_timeout = '45s'");
    DB::selectOne("SELECT set_config('application_name', ?, false)", [$schema.$workerMode.'_'.($argv[5] ?? '0')]);
    $payload = json_decode(base64_decode($argv[4], true), true, flags: JSON_THROW_ON_ERROR);
    try {
        match ($workerMode) {
            '--staff' => app(UpdateStaffAccount::class)->execute(User::query()->findOrFail($argv[3]), User::query()->findOrFail($payload['target']), $payload['data']),
            '--sell' => app(ApplyInventoryMovement::class)->execute(Branch::query()->findOrFail($payload['branch']), Product::query()->findOrFail($argv[3]), InventoryMovementType::ManualAdjustment, -1, 'race'),
            '--role' => app(UpdateRolePermissions::class)->execute(User::query()->findOrFail($argv[3]), $payload['role'], $payload['permissions']),
            '--custom-create' => app(CreateCustomRole::class)->execute(User::query()->findOrFail($argv[3]), $payload['label'], $payload['scope'], $payload['permissions']),
            '--custom-update' => app(UpdateCustomRole::class)->execute(User::query()->findOrFail($argv[3]), Role::query()->findOrFail($payload['role']), $payload['label'], $payload['scope'], $payload['permissions']),
            '--custom-archive' => app(ArchiveCustomRole::class)->execute(User::query()->findOrFail($argv[3]), Role::query()->findOrFail($payload['role'])),
        };
        $output = ['status' => 'success'];
    } catch (ValidationException $exception) {
        $output = ['status' => 'rejected', 'errors' => $exception->errors()];
    } catch (AuthorizationException) {
        $output = ['status' => 'forbidden'];
    }
    echo json_encode([...$output, 'pid' => DB::selectOne('SELECT pg_backend_pid() AS pid')->pid], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

/** @return array<string, string> */
function phase18Environment(array $connection): array
{
    return [
        'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => 'null',
        'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
        'DB_DATABASE' => $connection['database'], 'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'], 'DB_SSLMODE' => $connection['sslmode'],
    ];
}

/**
 * Starts the workers while the caller holds $hold (a closure taking row locks inside an open transaction), waits until
 * every worker is blocked behind those locks, then commits so they race.
 *
 * @param  list<array{mode: string, actor: string, payload: array<string, mixed>}>  $jobs
 * @return list<array<string, mixed>>
 */
function phase18Race(string $schema, array $connection, object $observer, Closure $hold, array $jobs): array
{
    DB::beginTransaction();
    $hold();
    $processes = [];
    foreach ($jobs as $index => $job) {
        $process = new Process([
            PHP_BINARY, __FILE__, $job['mode'], $schema, $job['actor'],
            base64_encode(json_encode($job['payload'], JSON_THROW_ON_ERROR)), (string) $index,
        ], dirname(__DIR__), phase18Environment($connection), timeout: 55);
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
        phase18Verify(hrtime(true) < $deadline, 'Workers did not overlap at the lock boundary.');
        usleep(10_000);
    } while (true);
    DB::commit();

    $results = [];
    foreach ($processes as $process) {
        phase18Verify($process->wait() === 0, 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
        $results[] = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
    }
    phase18Verify(count(array_unique(array_column($results, 'pid'))) === count($jobs), 'Workers did not use independent connections.');

    return $results;
}

$createdSchema = false;
try {
    $observer->statement('CREATE SCHEMA "'.$schema.'"');
    $createdSchema = true;
    phase18Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Fresh migration failed.');
    phase18Verify(Artisan::call('migrate:rollback', ['--step' => count(array_filter(glob(database_path('migrations/*.php')) ?: [], fn (string $file): bool => basename($file) >= '2026_09_24_165603')), '--force' => true, '--no-interaction' => true]) === 0, 'Phase 18 rollback failed.');
    phase18Verify(! DB::getSchemaBuilder()->hasTable('user_permission_overrides') && ! DB::getSchemaBuilder()->hasTable('notifications')
        && ! DB::getSchemaBuilder()->hasColumn('roles', 'scope'), 'Phase 18 rollback left schema behind.');
    /** Pre-Phase-18 rows (name-only roles and an assignment) must survive the forward migration and be backfilled. */
    foreach (PermissionCatalog::ROLES as $legacyRole) {
        DB::table('roles')->insert(['name' => $legacyRole, 'created_at' => now(), 'updated_at' => now()]);
    }
    $legacyUserId = DB::table('users')->insertGetId(['name' => 'Legacy Owner', 'email' => 'legacy@pongskilog.test', 'password' => 'x', 'is_active' => true, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('user_roles')->insert(['user_id' => $legacyUserId, 'role_id' => DB::table('roles')->where('name', 'owner')->value('id')]);
    phase18Verify(Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0, 'Phase 18 reapply failed.');
    $backfilled = DB::table('roles')->get(['name', 'label', 'is_system', 'scope', 'archived_at'])->keyBy('name');
    phase18Verify($backfilled['owner']->label === 'Owner' && $backfilled['owner']->is_system === true && $backfilled['owner']->scope === 'business'
        && $backfilled['cashier_kitchen']->label === 'Cashier + Kitchen' && $backfilled['cashier_kitchen']->scope === 'branch'
        && $backfilled->every(fn (object $role): bool => $role->archived_at === null)
        && User::query()->findOrFail($legacyUserId)->hasBusinessWideScope(), 'Custom role metadata backfill failed or lost existing rows.');
    $roleIndex = DB::selectOne("SELECT indexdef FROM pg_indexes WHERE schemaname = ? AND indexname = 'roles_active_label_unique'", [$schema]);
    phase18Verify($roleIndex !== null && str_contains((string) $roleIndex->indexdef, 'lower((label)::text)') && str_contains((string) $roleIndex->indexdef, 'archived_at IS NULL'), 'Active role name index is missing.');
    phase18Verify(Artisan::call('migrate:rollback', ['--step' => 1, '--force' => true, '--no-interaction' => true]) === 0
        && ! DB::getSchemaBuilder()->hasColumn('roles', 'label')
        && Artisan::call('migrate', ['--force' => true, '--no-interaction' => true]) === 0
        && DB::table('roles')->where('name', 'owner')->value('scope') === 'business', 'Custom role migration rollback/reapply failed.');
    DB::table('user_roles')->delete();
    DB::table('users')->delete();
    (new RbacSeeder)->run();
    $longNames = DB::select("SELECT indexname FROM pg_indexes WHERE schemaname = ? AND length(indexname) >= 63 AND tablename IN ('user_permission_overrides', 'notifications', 'roles')", [$schema]);
    phase18Verify($longNames === [], 'A Phase 18 index name reaches the 63-byte PostgreSQL limit.');
    echo 'FRESH/ROLLBACK/REAPPLY PASS '.$schema.PHP_EOL;

    // A: override uniqueness and effect constraint.
    $branch = Branch::factory()->create(['code' => 'MAIN']);
    $qave = Branch::factory()->create(['code' => 'QAVE']);
    $cashier = phase18User('cashier', $branch);
    $reportsId = Permission::query()->where('name', 'reports.view')->value('id');
    UserPermissionOverride::query()->create(['user_id' => $cashier->id, 'permission_id' => $reportsId, 'effect' => PermissionOverrideEffect::Allow]);
    $duplicateRejected = false;
    try {
        DB::transaction(fn () => DB::table('user_permission_overrides')->insert(['user_id' => $cashier->id, 'permission_id' => $reportsId, 'effect' => 'deny', 'created_at' => now(), 'updated_at' => now()]));
    } catch (QueryException $exception) {
        $duplicateRejected = $exception->getCode() === '23505';
    }
    $badEffectRejected = false;
    try {
        DB::transaction(fn () => DB::table('user_permission_overrides')->insert(['user_id' => $cashier->id, 'permission_id' => Permission::query()->where('name', 'pos.access')->value('id'), 'effect' => 'maybe', 'created_at' => now(), 'updated_at' => now()]));
    } catch (QueryException $exception) {
        $badEffectRejected = $exception->getCode() === '23514';
    }
    phase18Verify($duplicateRejected && $badEffectRejected && $cashier->hasPermission('reports.view'), 'A: override constraints did not hold.');
    echo 'CASE A PASS: one override per user and permission; effect is allow or deny only.'.PHP_EOL;

    // B: role baseline transaction and concurrent Cashier / Kitchen edits keep Cashier + Kitchen as the union.
    $admin = phase18User('super_admin');
    $results = phase18Race($schema, $connection, $observer, fn () => Role::query()->whereIn('name', ['cashier', 'kitchen_staff', 'cashier_kitchen'])->orderBy('id')->lockForUpdate()->get(), [
        ['mode' => '--role', 'actor' => (string) $admin->id, 'payload' => ['role' => 'cashier', 'permissions' => ['pos.access', 'transactions.view', 'reports.view']]],
        ['mode' => '--role', 'actor' => (string) $admin->id, 'payload' => ['role' => 'kitchen_staff', 'permissions' => ['kitchen.access']]],
    ]);
    phase18Verify(collect($results)->where('status', 'success')->count() === 2
        && phase18Baseline('cashier') === ['pos.access', 'qr_orders.access', 'transactions.view', 'reports.view']
        && phase18Baseline('kitchen_staff') === ['kitchen.access']
        && phase18Baseline('cashier_kitchen') === PermissionCatalog::union([phase18Baseline('cashier'), phase18Baseline('kitchen_staff')]), 'B: concurrent baseline edits left Cashier + Kitchen inconsistent.');
    echo 'CASE B PASS: concurrent Cashier and Kitchen baseline edits serialized; Cashier + Kitchen is their union.'.PHP_EOL;

    // C: the RBAC seeder never resets a customized baseline.
    (new RbacSeeder)->run();
    phase18Verify(phase18Baseline('cashier') === ['pos.access', 'qr_orders.access', 'transactions.view', 'reports.view']
        && phase18Baseline('super_admin') === PermissionCatalog::names()
        && UserPermissionOverride::query()->where('user_id', $cashier->id)->count() === 1, 'C: RbacSeeder reset live access.');
    echo 'CASE C PASS: RbacSeeder rerun kept the customized baselines and custom access.'.PHP_EOL;

    // D: Staff role + Branch update is atomic.
    $target = phase18User('cashier', $branch);
    $before = [$target->roles()->pluck('name')->all(), $target->branches()->pluck('code')->all()];
    try {
        app(UpdateStaffAccount::class)->execute($admin, $target, phase18Edit($target, ['role' => 'kitchen_staff', 'branch_ids' => [$qave->id, (string) Str::uuid()]]));
    } catch (ValidationException) {
    }
    phase18Verify([$target->roles()->pluck('name')->all(), $target->branches()->pluck('code')->all()] === $before, 'D: a failed Staff update left a partial change.');
    app(UpdateStaffAccount::class)->execute($admin, $target, phase18Edit($target, ['role' => 'owner', 'branch_ids' => []]));
    phase18Verify($target->roles()->pluck('name')->all() === ['owner'] && $target->branches()->count() === 0, 'D: role and Branch change did not commit together.');
    echo 'CASE D PASS: a failed Staff update changed nothing; role + Branch access committed together.'.PHP_EOL;

    // E: last active Super Admin protection.
    User::query()->whereKeyNot($admin->id)->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))->update(['is_active' => false]);
    $otherAdmin = phase18User('super_admin', active: false);
    $rejected = false;
    try {
        app(UpdateStaffAccount::class)->execute($admin, $admin, phase18Edit($admin, ['is_active' => false]));
    } catch (ValidationException) {
        $rejected = true;
    }
    phase18Verify($rejected && phase18ActiveSuperAdmins() === 1 && $otherAdmin->fresh()->is_active === false, 'E: the last active Super Admin was removable.');
    echo 'CASE E PASS: the last active Super Admin cannot be deactivated or demoted.'.PHP_EOL;

    // F: two Super Admins deactivating (then demoting) each other at the same time never leave zero.
    foreach (['deactivate' => ['is_active' => false], 'demote' => ['role' => 'owner', 'branch_ids' => []]] as $label => $change) {
        $a = phase18User('super_admin');
        $b = phase18User('super_admin');
        User::query()->whereHas('roles', fn ($roles) => $roles->where('name', 'super_admin'))->whereKeyNot([$a->id, $b->id])->update(['is_active' => false]);
        $results = phase18Race($schema, $connection, $observer, fn () => User::query()->whereKey([$a->id, $b->id])->orderBy('id')->lockForUpdate()->get(), [
            ['mode' => '--staff', 'actor' => (string) $a->id, 'payload' => ['target' => $b->id, 'data' => phase18Edit($b, $change)]],
            ['mode' => '--staff', 'actor' => (string) $b->id, 'payload' => ['target' => $a->id, 'data' => phase18Edit($a, $change)]],
        ]);
        phase18Verify(collect($results)->where('status', 'success')->count() === 1
            && phase18ActiveSuperAdmins() === 1, 'F: concurrent '.$label.' left '.phase18ActiveSuperAdmins().' active Super Admins.');
        echo 'CASE F PASS ('.$label.'): one of two crossing Super Admin '.$label.'s won; exactly one active Super Admin remains.'.PHP_EOL;
    }

    // G: notification read/unread persistence.
    $reader = phase18User('super_admin');
    $reader->notify(new AdminAlert('staff', 'One', 'body'));
    $reader->notify(new AdminAlert('stock', 'Two', 'body'));
    $reader->unreadNotifications()->first()?->markAsRead();
    phase18Verify($reader->unreadNotifications()->count() === 1 && $reader->notifications()->count() === 2, 'G: read state did not persist.');
    echo 'CASE G PASS: read/unread state persisted.'.PHP_EOL;

    // H: two concurrent sales empty a product together: exactly one out-of-stock alert per active Super Admin.
    $product = Product::factory()->create(['name' => 'Race Soda']);
    BranchProduct::factory()->for($branch)->for($product)->create(['tracks_inventory' => true]);
    $balance = BranchInventory::factory()->for($branch)->for($product)->create(['on_hand' => 2]);
    DB::table('notifications')->delete();
    $recipients = phase18ActiveSuperAdmins();
    $results = phase18Race($schema, $connection, $observer, fn () => BranchInventory::query()->whereKey($balance->id)->lockForUpdate()->sole(), [
        ['mode' => '--sell', 'actor' => $product->id, 'payload' => ['branch' => $branch->id]],
        ['mode' => '--sell', 'actor' => $product->id, 'payload' => ['branch' => $branch->id]],
    ]);
    phase18Verify(collect($results)->where('status', 'success')->count() === 2
        && $balance->fresh()->on_hand === 0
        && DB::table('notifications')->where('type', 'admin.stock')->count() === $recipients, 'H: stock alert was not deduplicated to one per transition.');
    echo 'CASE H PASS: two racing sales produced one out-of-stock alert per active Super Admin.'.PHP_EOL;

    // I: active Custom Role names are unique ignoring case at the database level; archived names may be reused.
    $admin = phase18User('super_admin');
    $supervisor = app(CreateCustomRole::class)->execute($admin, 'Branch Supervisor', 'branch', ['pos.access', 'reports.view']);
    $duplicateRejected = false;
    try {
        DB::transaction(fn () => DB::table('roles')->insert(['name' => 'custom_dupe', 'label' => 'BRANCH SUPERVISOR', 'is_system' => false, 'scope' => 'branch', 'created_at' => now(), 'updated_at' => now()]));
    } catch (QueryException $exception) {
        $duplicateRejected = $exception->getCode() === '23505';
    }
    $badScopeRejected = false;
    try {
        DB::transaction(fn () => DB::table('roles')->insert(['name' => 'custom_scope', 'label' => 'Scope Test', 'is_system' => false, 'scope' => 'everywhere', 'created_at' => now(), 'updated_at' => now()]));
    } catch (QueryException $exception) {
        $badScopeRejected = $exception->getCode() === '23514';
    }
    $archivedReuse = app(CreateCustomRole::class)->execute($admin, 'Old Shift', 'branch', []);
    app(ArchiveCustomRole::class)->execute($admin, $archivedReuse);
    $reused = app(CreateCustomRole::class)->execute($admin, 'old shift', 'business', []);
    phase18Verify($duplicateRejected && $badScopeRejected && $supervisor->name === 'custom_'.$supervisor->id && $reused->id !== $archivedReuse->id, 'I: role name/scope constraints did not hold.');
    echo 'CASE I PASS: active role names unique ignoring case; archived names reusable; scope limited to branch/business; stable custom_{id} key.'.PHP_EOL;

    // J: two admins create the same Custom Role name at the same moment: exactly one role exists.
    $secondAdmin = phase18User('super_admin');
    $results = phase18Race($schema, $connection, $observer, fn () => DB::statement('LOCK TABLE roles IN SHARE ROW EXCLUSIVE MODE'), [
        ['mode' => '--custom-create', 'actor' => (string) $admin->id, 'payload' => ['label' => 'Night Lead', 'scope' => 'branch', 'permissions' => ['pos.access']]],
        ['mode' => '--custom-create', 'actor' => (string) $secondAdmin->id, 'payload' => ['label' => 'night lead', 'scope' => 'business', 'permissions' => ['reports.view']]],
    ]);
    phase18Verify(collect($results)->where('status', 'success')->count() === 1
        && collect($results)->where('status', 'rejected')->count() === 1
        && Role::query()->whereNull('archived_at')->whereRaw('LOWER(label) = ?', ['night lead'])->count() === 1, 'J: racing creates produced a duplicate role name.');
    echo 'CASE J PASS: two racing creates of one name produced exactly one role.'.PHP_EOL;

    // K: two admins save the same Custom Role baseline at once: the result is one complete submitted baseline.
    $setA = ['pos.access', 'transactions.view', 'reports.view'];
    $setB = ['kitchen.access', 'customer_display.launch'];
    $results = phase18Race($schema, $connection, $observer, fn () => Role::query()->whereKey($supervisor->id)->lockForUpdate()->sole(), [
        ['mode' => '--custom-update', 'actor' => (string) $admin->id, 'payload' => ['role' => $supervisor->id, 'label' => 'Branch Supervisor', 'scope' => 'branch', 'permissions' => $setA]],
        ['mode' => '--custom-update', 'actor' => (string) $secondAdmin->id, 'payload' => ['role' => $supervisor->id, 'label' => 'Branch Supervisor', 'scope' => 'branch', 'permissions' => $setB]],
    ]);
    $final = PermissionCatalog::ordered($supervisor->permissions()->pluck('permissions.name')->all());
    phase18Verify(collect($results)->where('status', 'success')->count() === 2
        && in_array($final, [PermissionCatalog::withQrFollowingPos($setA), PermissionCatalog::withQrFollowingPos($setB)], true), 'K: concurrent custom role saves merged into '.implode(',', $final).'.');
    echo 'CASE K PASS: concurrent Custom Role saves serialized; the baseline is one complete submission ('.implode(', ', $final).').'.PHP_EOL;

    // L: archiving races assigning a Staff account to the role: never an archived role that still has users.
    $mover = phase18User('cashier', $branch);
    $results = phase18Race($schema, $connection, $observer, fn () => Role::query()->whereKey($supervisor->id)->lockForUpdate()->sole(), [
        ['mode' => '--custom-archive', 'actor' => (string) $admin->id, 'payload' => ['role' => $supervisor->id]],
        ['mode' => '--staff', 'actor' => (string) $secondAdmin->id, 'payload' => ['target' => $mover->id, 'data' => phase18Edit($mover, ['role' => $supervisor->name, 'branch_ids' => [$branch->id]])]],
    ]);
    $supervisor->refresh();
    $holders = DB::table('user_roles')->where('role_id', $supervisor->id)->count();
    phase18Verify(collect($results)->where('status', 'success')->count() === 1
        && (($supervisor->archived_at !== null && $holders === 0) || ($supervisor->archived_at === null && $holders === 1)), 'L: an archived role kept a Staff account.');
    echo 'CASE L PASS: archive vs assign serialized on the role row ('.($supervisor->archived_at === null ? 'assignment won' : 'archive won').').'.PHP_EOL;

    // M: the RBAC seeder rerun never touches Custom Roles.
    $customBefore = Role::query()->where('is_system', false)->orderBy('id')->get(['id', 'name', 'label', 'scope', 'archived_at'])->toArray();
    (new RbacSeeder)->run();
    phase18Verify(Role::query()->where('is_system', false)->orderBy('id')->get(['id', 'name', 'label', 'scope', 'archived_at'])->toArray() === $customBefore, 'M: RbacSeeder changed a Custom Role.');
    echo 'CASE M PASS: RbacSeeder rerun left every Custom Role unchanged.'.PHP_EOL;

    echo 'PHASE 18 POSTGRESQL VERIFICATION PASSED'.PHP_EOL;
} finally {
    DB::disconnect('pgsql');
    if ($createdSchema) {
        $observer->statement('DROP SCHEMA "'.$schema.'" CASCADE');
        echo 'DROPPED '.$schema.PHP_EOL;
    }
}
