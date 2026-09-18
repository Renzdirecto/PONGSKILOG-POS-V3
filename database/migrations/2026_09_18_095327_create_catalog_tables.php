<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('categories', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['is_active', 'sort_order']);
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('category_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->text('description')->nullable();
            $table->rawColumn('default_price', 'numeric(14, 2) CHECK (default_price >= 0)');
            $table->string('image_path')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category_id', 'is_active']);
        });

        Schema::create('branch_products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->rawColumn('price_override', 'numeric(14, 2) CHECK (price_override >= 0)')->nullable();
            $table->boolean('is_available')->default(true);
            $table->boolean('tracks_inventory')->default(false);
            $table->rawColumn('low_stock_threshold', 'integer CHECK (low_stock_threshold >= 0)')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
            $table->index(['branch_id', 'is_available']);
            $table->index('product_id');
        });

        Schema::create('modifier_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->enum('selection_type', ['single', 'multiple']);
            $table->rawColumn('min_select', 'integer CHECK (min_select >= 0)')->default(0);
            $table->rawColumn('max_select', 'integer CHECK (max_select >= 0 AND max_select >= min_select)')->default(1);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('modifier_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('modifier_group_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->rawColumn('price_delta', 'numeric(14, 2) CHECK (price_delta >= 0)');
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['modifier_group_id', 'sort_order']);
        });

        Schema::create('product_modifier_groups', function (Blueprint $table) {
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('modifier_group_id')->constrained()->restrictOnDelete();

            $table->unique(['product_id', 'modifier_group_id']);
            $table->index('modifier_group_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_modifier_groups');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifier_groups');
        Schema::dropIfExists('branch_products');
        Schema::dropIfExists('products');
        Schema::dropIfExists('categories');
    }
};
