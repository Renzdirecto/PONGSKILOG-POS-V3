<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const REASONS = ['complimentary', 'wastage', 'damaged', 'staff_meal', 'other'];

    /**
     * Phase 20 Stock Correction reasons. Complimentary and Staff meal stay only for historical rows: a free Product
     * is now always a Giveaway.
     *
     * @var list<string>
     */
    private const CORRECTION_REASONS = ['physical_count', 'found_stock', 'missing_stock'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /** Existing rows were all deductions; `quantity` stays the positive size of the correction. */
        Schema::table('store_session_inventory_adjustments', function (Blueprint $table) {
            $table->enum('direction', ['decrease', 'increase'])->default('decrease');
        });

        $this->reasonCodes([...self::REASONS, ...self::CORRECTION_REASONS], self::REASONS);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $query = DB::table('store_session_inventory_adjustments');
        if ((clone $query)->whereIn('reason_code', self::CORRECTION_REASONS)->exists() || (clone $query)->where('direction', 'increase')->exists()) {
            throw new RuntimeException('Stock Correction history exists; it cannot be rolled back without losing stock history.');
        }

        $this->reasonCodes(self::REASONS, [...self::REASONS, ...self::CORRECTION_REASONS]);
        Schema::table('store_session_inventory_adjustments', function (Blueprint $table) {
            $table->dropColumn('direction');
        });
    }

    /**
     * Replaces the reason_code CHECK constraint (Laravel enum) with the given values.
     *
     * @param  list<string>  $values
     * @param  list<string>  $current
     */
    private function reasonCodes(array $values, array $current): void
    {
        $table = 'store_session_inventory_adjustments';
        $quoted = fn (array $list): string => implode(', ', array_map(fn (string $value): string => "'{$value}'", $list));

        if (DB::getDriverName() !== 'sqlite') {
            $constraints = DB::select(
                "SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE ?",
                [$table, '%reason_code%'],
            );
            if (count($constraints) !== 1) {
                throw new RuntimeException("Expected exactly one {$table}.reason_code CHECK constraint.");
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$constraints[0]->conname}\"");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_reason_code_check CHECK (reason_code IN ({$quoted($values)}))");

            return;
        }

        /** Preserve the original columns, CHECK constraints, foreign keys and indexes during the SQLite rebuild. */
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
        $from = "\"reason_code\" in ({$quoted($current)})";
        if (! is_string($definition) || substr_count($definition, $from) !== 1) {
            throw new RuntimeException("Unexpected SQLite definition for {$table}.reason_code.");
        }
        $definition = str_replace($from, "\"reason_code\" in ({$quoted($values)})", $definition);
        $definition = str_replace("CREATE TABLE \"{$table}\"", "CREATE TABLE \"{$table}_correction_rebuild\"", $definition);
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);

        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(function () use ($table, $definition, $indexes): void {
                DB::statement($definition);
                DB::statement("INSERT INTO {$table}_correction_rebuild SELECT * FROM {$table}");
                DB::statement("DROP TABLE {$table}");
                DB::statement("ALTER TABLE {$table}_correction_rebuild RENAME TO {$table}");
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
