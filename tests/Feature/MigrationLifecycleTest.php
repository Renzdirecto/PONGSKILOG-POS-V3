<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

test('fresh migrations can fully roll back and reapply on an isolated SQLite connection', function () {
    config(['database.connections.migration_smoke' => [
        'driver' => 'sqlite',
        'database' => ':memory:',
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]]);
    $options = ['--database' => 'migration_smoke', '--force' => true, '--no-interaction' => true];
    $schema = Schema::connection('migration_smoke');

    try {
        $this->artisan('migrate:fresh', $options)->assertSuccessful();
        expect($schema->hasColumn('payments', 'payment_group_id'))->toBeTrue();
        expect($schema->hasColumn('payments', 'payment_context'))->toBeTrue();

        $this->artisan('migrate:rollback', $options)->assertSuccessful();
        expect($schema->hasTable('payments'))->toBeFalse();
        expect($schema->hasTable('orders'))->toBeFalse();

        $this->artisan('migrate', $options)->assertSuccessful();
        expect($schema->hasIndex('payments', ['payment_group_id']))->toBeTrue();
        expect($schema->hasIndex('payments', ['payment_context']))->toBeTrue();
        expect($schema->hasColumn('orders', 'original_total'))->toBeTrue();
    } finally {
        DB::purge('migration_smoke');
    }
});
