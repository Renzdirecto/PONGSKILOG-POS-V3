<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('order_adjustments', function (Blueprint $table): void {
            /** Cash/Cashless refund source; historical rows stay null rather than receiving a guessed allocation. */
            $table->rawColumn('cash_amount', 'numeric(14, 2) CHECK (cash_amount >= 0)')->nullable()->after('amount');
            $table->rawColumn('cashless_amount', 'numeric(14, 2) CHECK (cashless_amount >= 0)')->nullable()->after('cash_amount');
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_adjustments ADD CONSTRAINT order_adjustments_allocation_check CHECK ((cash_amount IS NULL AND cashless_amount IS NULL) OR (cash_amount IS NOT NULL AND cashless_amount IS NOT NULL AND cash_amount + cashless_amount = amount))');
        }

        /** Expected balances follow exact reconciliation math, which may be negative after large expenses. */
        $this->expectedBalanceChecks(false);

        Schema::table('store_sessions', function (Blueprint $table): void {
            $table->json('reconciliation_snapshot')->nullable()->after('closing_note');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::table('store_sessions')->where('expected_cash_amount', '<', 0)->orWhere('expected_cashless_amount', '<', 0)->exists()) {
            throw new RuntimeException('Cannot roll back while negative expected closing balances exist. Preserve their history and roll forward.');
        }
        if (DB::table('order_adjustments')->whereNotNull('cash_amount')->exists()) {
            throw new RuntimeException('Cannot roll back while allocated payment corrections exist. Preserve their history and roll forward.');
        }

        Schema::table('store_sessions', function (Blueprint $table): void {
            $table->dropColumn('reconciliation_snapshot');
        });

        $this->expectedBalanceChecks(true);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE order_adjustments DROP CONSTRAINT order_adjustments_allocation_check');
        }

        Schema::table('order_adjustments', function (Blueprint $table): void {
            $table->dropColumn(['cash_amount', 'cashless_amount']);
        });
    }

    private function expectedBalanceChecks(bool $enabled): void
    {
        $columns = ['expected_cash_amount', 'expected_cashless_amount'];

        if (DB::getDriverName() !== 'sqlite') {
            foreach ($columns as $column) {
                if ($enabled) {
                    DB::statement("ALTER TABLE store_sessions ADD CONSTRAINT store_sessions_{$column}_check CHECK ({$column} >= 0)");

                    continue;
                }

                $constraints = DB::select(
                    "SELECT conname FROM pg_constraint WHERE conrelid = 'store_sessions'::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE ?",
                    ['%'.$column.' >= %'],
                );
                if (count($constraints) !== 1) {
                    throw new RuntimeException("Expected exactly one {$column} CHECK constraint.");
                }
                DB::statement('ALTER TABLE store_sessions DROP CONSTRAINT "'.$constraints[0]->conname.'"');
            }

            return;
        }

        /** Preserve the original inline CHECK constraints, foreign keys and indexes during SQLite rebuild. */
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'store_sessions'")->sql;
        if (! is_string($definition)) {
            throw new RuntimeException('Missing SQLite store session table definition.');
        }
        foreach ($columns as $column) {
            $checked = "\"{$column}\" numeric(14, 2) CHECK ({$column} >= 0)";
            $unchecked = "\"{$column}\" numeric(14, 2)";
            $from = $enabled ? $unchecked.',' : $checked.',';
            $to = $enabled ? $checked.',' : $unchecked.',';
            if (substr_count($definition, $from) !== 1) {
                throw new RuntimeException("Unexpected SQLite definition for {$column}.");
            }
            $definition = str_replace($from, $to, $definition);
        }
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'store_sessions' AND sql IS NOT NULL");
        $definition = str_replace('CREATE TABLE "store_sessions"', 'CREATE TABLE "store_sessions_close_rebuild"', $definition);

        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(function () use ($definition, $indexes): void {
                DB::statement($definition);
                DB::statement('INSERT INTO store_sessions_close_rebuild SELECT * FROM store_sessions');
                DB::statement('DROP TABLE store_sessions');
                DB::statement('ALTER TABLE store_sessions_close_rebuild RENAME TO store_sessions');
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
