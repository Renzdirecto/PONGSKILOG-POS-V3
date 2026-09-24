<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16E Owner Operations & Pamamalengke. Additive only: no existing column, row or constraint is changed except
 * the new `products.no_recipe_needed` flag (default false). Ingredient quantities are exact numeric(18,4) values in
 * the Ingredient's base unit; money is numeric(14,2) or integer centavos, never floating point.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operation_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 60);
            $table->string('description', 200)->nullable();
            $table->string('icon', 20)->default('box');
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('operation_plan_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_plan_id')->constrained()->restrictOnDelete();
            /** A sellable Product belongs to at most one active Plan, so Plan sales are never double counted. */
            $table->foreignUuid('product_id')->unique()->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->index('operation_plan_id');
        });

        Schema::create('ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 80)->unique();
            $table->string('icon', 20)->default('box');
            $table->rawColumn('base_unit', "varchar(10) CHECK (base_unit IN ('pc', 'pack', 'bottle', 'ml', 'L', 'g', 'kg'))");
            $table->rawColumn('target_quantity', 'numeric(18, 4) CHECK (target_quantity >= 0)')->default(0);
            $table->string('purchase_unit_name', 30)->nullable();
            $table->rawColumn('purchase_unit_size', 'numeric(18, 4) CHECK (purchase_unit_size > 0)')->nullable();
            $table->rawColumn('purchase_unit_cost', 'numeric(14, 2) CHECK (purchase_unit_cost >= 0)')->nullable();
            $table->enum('replenishment_rule', ['top_up', 'reorder', 'none'])->default('top_up');
            $table->rawColumn('reorder_point', 'numeric(18, 4) CHECK (reorder_point >= 0)')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();
        });

        Schema::create('operation_plan_ingredients', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('operation_plan_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->timestamps();

            $table->unique(['operation_plan_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });

        /** One canonical physical balance per Branch + Ingredient, shared by every Plan that uses the Ingredient. */
        Schema::create('branch_ingredient_stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->rawColumn('on_hand', 'numeric(18, 4)')->default(0);
            $table->rawColumn('version', 'bigint CHECK (version >= 0)')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->boolean('no_recipe_needed')->default(false);
        });

        Schema::create('recipes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('size_modifier_option_id')->nullable()->constrained('modifier_options')->restrictOnDelete();
            /** The size option id, or `base` for a Product without sizes, so the pair is unique without NULL semantics. */
            $table->string('size_key', 36);
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'size_key']);
        });

        Schema::create('recipe_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('recipe_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->rawColumn('quantity', 'numeric(18, 4) CHECK (quantity > 0)');
            $table->timestamps();

            $table->unique(['recipe_id', 'ingredient_id']);
            $table->index('ingredient_id');
        });

        /**
         * The recipe, cost basis and Plan in force when an Order first committed a Product/size. Immutable: an Edit or
         * Void always works from this snapshot and the recorded movements, never from today's recipe.
         */
        Schema::create('order_recipe_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->uuid('size_modifier_option_id')->nullable();
            $table->string('size_key', 36);
            $table->string('product_name_snapshot');
            $table->string('size_name_snapshot')->nullable();
            $table->enum('recipe_state', ['recipe', 'missing', 'not_needed']);
            $table->foreignUuid('operation_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->timestamp('created_at');

            $table->unique(['order_id', 'product_id', 'size_key']);
            $table->index(['operation_plan_id', 'created_at']);
        });

        Schema::create('order_recipe_snapshot_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_recipe_snapshot_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->rawColumn('quantity_per_unit', 'numeric(18, 4) CHECK (quantity_per_unit > 0)');
            /** Purchase-unit cost and size in force at commitment; null when the cost was unknown (never assumed ₱0). */
            $table->rawColumn('cost_basis_cents', 'bigint CHECK (cost_basis_cents >= 0)')->nullable();
            $table->rawColumn('cost_basis_quantity', 'numeric(18, 4) CHECK (cost_basis_quantity > 0)')->nullable();

            $table->unique(['order_recipe_snapshot_id', 'ingredient_id']);
        });

        Schema::create('pamamalengke_purchases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('operation_plan_id')->nullable()->constrained()->restrictOnDelete();
            /** The one canonical Store Purchase / Expense; Pamamalengke keeps no financial ledger of its own. */
            $table->foreignUuid('store_session_expense_id')->unique()->constrained()->restrictOnDelete();
            $table->rawColumn('estimated_total', 'numeric(14, 2) CHECK (estimated_total >= 0)')->nullable();
            $table->boolean('estimate_complete')->default(true);
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('intent_hash', 64);
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
            $table->index(['operation_plan_id', 'created_at']);
        });

        Schema::create('ingredient_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->enum('movement_type', [
                'opening_balance', 'sale_consumption', 'order_edit_adjustment', 'void_restoration',
                'purchase_restock', 'wastage', 'count_correction',
            ]);
            $table->rawColumn('quantity_delta', 'numeric(18, 4) CHECK (quantity_delta <> 0)');
            $table->rawColumn('balance_after', 'numeric(18, 4)');
            /** Signed estimated cost of the consumption this row represents (restorations are negative); null = unknown. */
            $table->bigInteger('estimated_cost_cents')->nullable();
            $table->foreignUuid('order_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('order_recipe_snapshot_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('operation_plan_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('pamamalengke_purchase_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_expense_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('reason_code', 40)->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at');

            $table->index(['branch_id', 'ingredient_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
            $table->index('order_id');
            $table->index('pamamalengke_purchase_id');
        });

        /** A committed sale consumes each snapshot line once and a Void restores it once, even under retries. */
        DB::statement("CREATE UNIQUE INDEX ingredient_movements_sale_once ON ingredient_movements (order_recipe_snapshot_id, ingredient_id) WHERE movement_type = 'sale_consumption'");
        DB::statement("CREATE UNIQUE INDEX ingredient_movements_void_once ON ingredient_movements (order_recipe_snapshot_id, ingredient_id) WHERE movement_type = 'void_restoration'");

        Schema::create('pamamalengke_purchase_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('pamamalengke_purchase_id')->constrained()->restrictOnDelete();
            $table->enum('line_type', ['ingredient', 'manual']);
            $table->foreignUuid('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name_snapshot', 80);
            $table->string('unit_label', 30);
            $table->boolean('was_recommended')->default(false);
            $table->rawColumn('recommended_quantity', 'numeric(18, 4) CHECK (recommended_quantity >= 0)')->nullable();
            $table->rawColumn('actual_quantity', 'numeric(18, 4) CHECK (actual_quantity > 0)');
            $table->rawColumn('estimated_unit_cost', 'numeric(14, 2) CHECK (estimated_unit_cost >= 0)')->nullable();
            $table->rawColumn('actual_unit_cost', 'numeric(14, 2) CHECK (actual_unit_cost >= 0)');
            $table->rawColumn('line_total', 'numeric(14, 2) CHECK (line_total >= 0)');
            $table->rawColumn('purchase_unit_size', 'numeric(18, 4) CHECK (purchase_unit_size > 0)')->nullable();
            $table->rawColumn('base_quantity', 'numeric(18, 4) CHECK (base_quantity > 0)')->nullable();
            $table->foreignUuid('ingredient_movement_id')->nullable()->unique()->constrained()->restrictOnDelete();
            $table->string('note', 150)->nullable();
            $table->timestamps();

            $table->index('ingredient_id');
        });

        /** The next run's working list for one Branch + Plan: manual items and "skip this run" marks. */
        Schema::create('pamamalengke_list_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('operation_plan_id')->constrained()->restrictOnDelete();
            $table->enum('entry_type', ['manual', 'skip']);
            $table->foreignUuid('ingredient_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('name', 80)->nullable();
            $table->rawColumn('quantity', 'numeric(18, 4) CHECK (quantity > 0)')->nullable();
            $table->string('unit', 30)->nullable();
            $table->rawColumn('estimated_unit_cost', 'numeric(14, 2) CHECK (estimated_unit_cost >= 0)')->nullable();
            $table->string('note', 150)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['branch_id', 'operation_plan_id', 'entry_type', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pamamalengke_list_entries');
        Schema::dropIfExists('pamamalengke_purchase_items');
        Schema::dropIfExists('ingredient_movements');
        Schema::dropIfExists('pamamalengke_purchases');
        Schema::dropIfExists('order_recipe_snapshot_lines');
        Schema::dropIfExists('order_recipe_snapshots');
        Schema::dropIfExists('recipe_lines');
        Schema::dropIfExists('recipes');
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('no_recipe_needed');
        });
        Schema::dropIfExists('branch_ingredient_stocks');
        Schema::dropIfExists('operation_plan_ingredients');
        Schema::dropIfExists('ingredients');
        Schema::dropIfExists('operation_plan_products');
        Schema::dropIfExists('operation_plans');
    }
};
