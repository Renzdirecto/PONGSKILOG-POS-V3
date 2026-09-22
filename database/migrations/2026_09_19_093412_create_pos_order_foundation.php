<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_tables', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['branch_id', 'name']);
        });
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('order_number', 32)->index();
            $table->enum('source', ['pos', 'customer_qr']);
            $table->enum('order_type', ['dine_in', 'take_out']);
            $table->string('customer_label', 150)->nullable();
            $table->foreignUuid('branch_table_id')->nullable()->constrained()->restrictOnDelete();
            $table->enum('commercial_status', ['draft', 'submitted', 'active', 'completed', 'voided', 'archived_unclaimed']);
            $table->enum('payment_status', ['unpaid', 'partial', 'paid']);
            $table->enum('payment_term', ['immediate', 'pay_later'])->nullable();
            $table->enum('kitchen_status', ['not_sent', 'kitchen', 'preparing', 'ready', 'done']);
            $table->rawColumn('subtotal', 'numeric(14,2) CHECK (subtotal >= 0)');
            $table->rawColumn('total', 'numeric(14,2) CHECK (total >= 0)');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->foreignId('loaded_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->rawColumn('version', 'bigint CHECK (version > 0)')->default(1);
            $table->timestamps();
            $table->unique(['branch_id', 'order_number']);
            $table->index(['branch_id', 'created_at']);
            $table->index(['branch_id', 'commercial_status', 'created_at']);
            $table->index(['branch_id', 'payment_status', 'created_at']);
            $table->index(['branch_id', 'kitchen_status', 'created_at']);
            $table->index(['branch_id', 'archived_at']);
        });
        Schema::create('order_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->nullable()->constrained()->nullOnDelete();
            $table->string('product_name_snapshot');
            $table->rawColumn('unit_price', 'numeric(14,2) CHECK (unit_price >= 0)');
            $table->rawColumn('quantity', 'integer CHECK (quantity > 0)');
            $table->rawColumn('line_total', 'numeric(14,2) CHECK (line_total >= 0)');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index('order_id');
        });
        Schema::create('order_item_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_item_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('modifier_option_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group_name_snapshot');
            $table->string('option_name_snapshot');
            $table->rawColumn('price_delta_snapshot', 'numeric(14,2) CHECK (price_delta_snapshot >= 0)');
            $table->rawColumn('quantity', 'integer CHECK (quantity > 0)');
            $table->index('order_item_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_modifiers');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('branch_tables');
    }
};
