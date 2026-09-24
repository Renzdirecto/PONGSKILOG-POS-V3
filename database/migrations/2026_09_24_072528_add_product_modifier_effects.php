<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 16E Manual QA follow-up: Add-on / Modifier Ingredient effects. Additive only; no existing column, row or
 * constraint changes. An effect belongs to one Product + one Add-on option (reusable Modifier Groups never change the
 * stock of unrelated Products). Orders keep their own immutable copy of the effects in force at first commitment.
 * Long constraint names are explicit because PostgreSQL truncates identifiers at 63 bytes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('product_modifier_effects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('modifier_option_id')->constrained()->restrictOnDelete();
            $table->foreignId('updated_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['product_id', 'modifier_option_id']);
            $table->index('modifier_option_id');
        });

        Schema::create('product_modifier_effect_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('product_modifier_effect_id')->constrained(indexName: 'product_modifier_effect_lines_effect_foreign')->cascadeOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->rawColumn('quantity', 'numeric(18, 4) CHECK (quantity > 0)');
            $table->timestamps();

            $table->unique(['product_modifier_effect_id', 'ingredient_id'], 'product_modifier_effect_lines_unique');
            $table->index('ingredient_id');
        });

        /**
         * The Add-on effect in force when an Order first committed that Add-on on a Product/size snapshot. A row with no
         * lines records "no Ingredient effect", so a later effect edit never applies to this Order retroactively.
         */
        Schema::create('order_recipe_snapshot_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_recipe_snapshot_id')->constrained(indexName: 'order_recipe_snapshot_modifiers_snapshot_foreign')->restrictOnDelete();
            $table->uuid('modifier_option_id');
            $table->string('option_name_snapshot');
            $table->string('group_name_snapshot')->nullable();
            $table->timestamp('created_at');

            $table->unique(['order_recipe_snapshot_id', 'modifier_option_id'], 'order_recipe_snapshot_modifiers_unique');
        });

        Schema::create('order_recipe_snapshot_modifier_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_recipe_snapshot_modifier_id')->constrained(indexName: 'order_recipe_snapshot_modifier_lines_modifier_foreign')->restrictOnDelete();
            $table->foreignUuid('ingredient_id')->constrained()->restrictOnDelete();
            $table->rawColumn('quantity_per_selection', 'numeric(18, 4) CHECK (quantity_per_selection > 0)');
            /** Purchase-unit cost and size in force at commitment; null when the cost was unknown (never assumed ₱0). */
            $table->rawColumn('cost_basis_cents', 'bigint CHECK (cost_basis_cents >= 0)')->nullable();
            $table->rawColumn('cost_basis_quantity', 'numeric(18, 4) CHECK (cost_basis_quantity > 0)')->nullable();

            $table->unique(['order_recipe_snapshot_modifier_id', 'ingredient_id'], 'order_recipe_snapshot_modifier_lines_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_recipe_snapshot_modifier_lines');
        Schema::dropIfExists('order_recipe_snapshot_modifiers');
        Schema::dropIfExists('product_modifier_effect_lines');
        Schema::dropIfExists('product_modifier_effects');
    }
};
