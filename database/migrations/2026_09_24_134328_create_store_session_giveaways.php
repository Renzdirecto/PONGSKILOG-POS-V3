<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** @var list<string> */
    private const INGREDIENT_TYPES = [
        'opening_balance', 'sale_consumption', 'order_edit_adjustment', 'void_restoration',
        'purchase_restock', 'wastage', 'count_correction',
    ];

    /** @var list<string> */
    private const INVENTORY_TYPES = [
        'sale', 'pay_later_commit', 'order_edit_delta', 'void_restore',
        'manual_adjustment', 'store_purchase_restock', 'transfer_out', 'transfer_in',
    ];

    /** @var list<string> */
    private const GIVEAWAY_TYPES = ['giveaway', 'giveaway_reversal'];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        /**
         * A Product given away free during an OPEN Store Session: a stock-out record with ₱0 revenue that is never an
         * Order, Payment or Store Expense. Product, size, selections and the recipe basis are snapshotted; the stock
         * effect lives in the canonical ledgers (one Product-stock movement or Ingredient movements, never both).
         */
        Schema::create('store_session_giveaways', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->string('product_name_snapshot');
            $table->string('size_key', 64);
            $table->string('size_name_snapshot')->nullable();
            $table->json('selections');
            $table->rawColumn('quantity', 'integer CHECK (quantity > 0)');
            $table->enum('stock_mode', ['recipe', 'product_stock', 'none']);
            $table->json('stock_basis')->nullable();
            $table->foreignUuid('inventory_movement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->enum('reason_code', ['complimentary', 'service_recovery', 'promotion', 'staff_meal', 'other']);
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('intent_hash', 64);
            $table->timestamps();

            $table->index(['store_session_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });

        /** At most one compensating reversal per Giveaway, restoring exactly its recorded stock effect. */
        Schema::create('store_session_giveaway_reversals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('giveaway_id')->unique()->constrained('store_session_giveaways')->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('inventory_movement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->text('reason');
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('intent_hash', 64);
            $table->timestamps();
        });

        Schema::table('ingredient_movements', function (Blueprint $table) {
            $table->uuid('store_session_giveaway_id')->nullable()->index();
        });
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('ingredient_movements', function (Blueprint $table) {
                $table->foreign('store_session_giveaway_id')->references('id')->on('store_session_giveaways')->restrictOnDelete();
            });
        }

        $this->movementTypes('ingredient_movements', [...self::INGREDIENT_TYPES, ...self::GIVEAWAY_TYPES], self::INGREDIENT_TYPES);
        $this->movementTypes('inventory_movements', [...self::INVENTORY_TYPES, ...self::GIVEAWAY_TYPES], self::INVENTORY_TYPES);

        /** A Giveaway deducts each Ingredient once and its reversal restores it once, even under retries. */
        DB::statement("CREATE UNIQUE INDEX ingredient_movements_giveaway_once ON ingredient_movements (store_session_giveaway_id, ingredient_id) WHERE movement_type = 'giveaway'");
        DB::statement("CREATE UNIQUE INDEX ingredient_movements_giveaway_reversal_once ON ingredient_movements (store_session_giveaway_id, ingredient_id) WHERE movement_type = 'giveaway_reversal'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (['ingredient_movements', 'inventory_movements'] as $table) {
            if (DB::table($table)->whereIn('movement_type', self::GIVEAWAY_TYPES)->exists()) {
                throw new RuntimeException("Giveaway history exists in {$table}; it cannot be rolled back without losing stock history.");
            }
        }
        DB::statement('DROP INDEX ingredient_movements_giveaway_once');
        DB::statement('DROP INDEX ingredient_movements_giveaway_reversal_once');
        $this->movementTypes('inventory_movements', self::INVENTORY_TYPES, [...self::INVENTORY_TYPES, ...self::GIVEAWAY_TYPES]);
        $this->movementTypes('ingredient_movements', self::INGREDIENT_TYPES, [...self::INGREDIENT_TYPES, ...self::GIVEAWAY_TYPES]);
        if (DB::getDriverName() !== 'sqlite') {
            Schema::table('ingredient_movements', function (Blueprint $table) {
                $table->dropForeign(['store_session_giveaway_id']);
            });
        }
        Schema::table('ingredient_movements', function (Blueprint $table) {
            $table->dropIndex(['store_session_giveaway_id']);
            $table->dropColumn('store_session_giveaway_id');
        });
        Schema::dropIfExists('store_session_giveaway_reversals');
        Schema::dropIfExists('store_session_giveaways');
    }

    /**
     * Replaces the movement_type CHECK constraint (Laravel enum) with the given values.
     *
     * @param  list<string>  $values
     * @param  list<string>  $current
     */
    private function movementTypes(string $table, array $values, array $current): void
    {
        $quoted = fn (array $list): string => implode(', ', array_map(fn (string $value): string => "'{$value}'", $list));

        if (DB::getDriverName() !== 'sqlite') {
            $constraints = DB::select(
                "SELECT conname FROM pg_constraint WHERE conrelid = ?::regclass AND contype = 'c' AND pg_get_constraintdef(oid) LIKE ?",
                [$table, '%movement_type%'],
            );
            if (count($constraints) !== 1) {
                throw new RuntimeException("Expected exactly one {$table}.movement_type CHECK constraint.");
            }
            DB::statement("ALTER TABLE {$table} DROP CONSTRAINT \"{$constraints[0]->conname}\"");
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$table}_movement_type_check CHECK (movement_type IN ({$quoted($values)}))");

            return;
        }

        /** Preserve the original columns, CHECK constraints, foreign keys and indexes during the SQLite rebuild. */
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table])->sql;
        $from = "\"movement_type\" in ({$quoted($current)})";
        if (! is_string($definition) || substr_count($definition, $from) !== 1) {
            throw new RuntimeException("Unexpected SQLite definition for {$table}.movement_type.");
        }
        $definition = str_replace($from, "\"movement_type\" in ({$quoted($values)})", $definition);
        $definition = str_replace("CREATE TABLE \"{$table}\"", "CREATE TABLE \"{$table}_giveaway_rebuild\"", $definition);
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = ? AND sql IS NOT NULL", [$table]);

        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(function () use ($table, $definition, $indexes): void {
                DB::statement($definition);
                DB::statement("INSERT INTO {$table}_giveaway_rebuild SELECT * FROM {$table}");
                DB::statement("DROP TABLE {$table}");
                DB::statement("ALTER TABLE {$table}_giveaway_rebuild RENAME TO {$table}");
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
