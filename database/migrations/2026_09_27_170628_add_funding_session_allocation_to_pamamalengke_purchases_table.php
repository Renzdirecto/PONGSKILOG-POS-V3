<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 20: a Pamamalengke purchase no longer needs an OPEN Store Session. `store_session_id` is the funding Store
 * Session chosen by the buyer. Funded by the OPEN session it is still that session's canonical Store Purchase (the
 * expense row); funded by a CLOSED session it is an allocation only, with no expense row, so the sealed close result
 * never changes. The purchase therefore records its own payment source, and the expense link becomes optional.
 */
return new class extends Migration
{
    private const TABLE = 'pamamalengke_purchases';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->enum('payment_source', ['cash', 'cashless'])->nullable();
        });
        DB::statement('UPDATE pamamalengke_purchases SET payment_source = (SELECT store_session_expenses.payment_source FROM store_session_expenses WHERE store_session_expenses.id = pamamalengke_purchases.store_session_expense_id)');

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqlite([
                '"store_session_expense_id" varchar not null' => '"store_session_expense_id" varchar',
                '"payment_source" varchar check ("payment_source" in (\'cash\', \'cashless\'))' => '"payment_source" varchar not null check ("payment_source" in (\'cash\', \'cashless\'))',
            ]);

            return;
        }

        DB::statement('ALTER TABLE pamamalengke_purchases ALTER COLUMN payment_source SET NOT NULL');
        DB::statement('ALTER TABLE pamamalengke_purchases ALTER COLUMN store_session_expense_id DROP NOT NULL');
        /** The funding Store Session always belongs to the purchase's Branch, even if application code were wrong. */
        DB::statement('CREATE UNIQUE INDEX store_sessions_branch_id_id_unique ON store_sessions (branch_id, id)');
        DB::statement('ALTER TABLE pamamalengke_purchases ADD CONSTRAINT pamamalengke_purchases_branch_session_foreign FOREIGN KEY (branch_id, store_session_id) REFERENCES store_sessions (branch_id, id) ON DELETE RESTRICT');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table(self::TABLE)->whereNull('store_session_expense_id')->exists()) {
            throw new RuntimeException('Closed-session Pamamalengke allocations exist; they cannot be rolled back without losing purchase history.');
        }

        if (DB::getDriverName() === 'sqlite') {
            $this->rebuildSqlite([
                '"store_session_expense_id" varchar,' => '"store_session_expense_id" varchar not null,',
                '"payment_source" varchar not null check' => '"payment_source" varchar check',
            ]);
        } else {
            DB::statement('ALTER TABLE pamamalengke_purchases DROP CONSTRAINT pamamalengke_purchases_branch_session_foreign');
            DB::statement('DROP INDEX store_sessions_branch_id_id_unique');
            DB::statement('ALTER TABLE pamamalengke_purchases ALTER COLUMN store_session_expense_id SET NOT NULL');
        }

        Schema::table(self::TABLE, function (Blueprint $table) {
            $table->dropColumn('payment_source');
        });
    }

    /**
     * Rebuilds the SQLite table with an edited definition, preserving its columns, CHECK constraints, foreign keys and
     * indexes (SQLite cannot alter a column's nullability in place).
     *
     * @param  array<string, string>  $replacements
     */
    private function rebuildSqlite(array $replacements): void
    {
        $table = self::TABLE;
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
        if (! is_string($definition)) {
            throw new RuntimeException("Unexpected SQLite definition for {$table}.");
        }
        foreach ($replacements as $from => $to) {
            if (substr_count($definition, $from) !== 1) {
                throw new RuntimeException("Unexpected SQLite definition for {$table}: {$from}");
            }
            $definition = str_replace($from, $to, $definition);
        }
        $definition = str_replace("CREATE TABLE \"{$table}\"", "CREATE TABLE \"{$table}_funding_rebuild\"", $definition);
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);

        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(function () use ($table, $definition, $indexes): void {
                DB::statement($definition);
                DB::statement("INSERT INTO {$table}_funding_rebuild SELECT * FROM {$table}");
                DB::statement("DROP TABLE {$table}");
                DB::statement("ALTER TABLE {$table}_funding_rebuild RENAME TO {$table}");
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
