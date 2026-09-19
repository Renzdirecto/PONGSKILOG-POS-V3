<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_inventory', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->rawColumn('on_hand', 'bigint CHECK (on_hand >= 0)')->default(0);
            $table->rawColumn('version', 'bigint CHECK (version >= 0)')->default(0);
            $table->timestamps();

            $table->unique(['branch_id', 'product_id']);
        });

        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->enum('movement_type', [
                'sale', 'pay_later_commit', 'order_edit_delta', 'void_restore',
                'manual_adjustment', 'store_purchase_restock', 'transfer_out', 'transfer_in',
            ]);
            $table->rawColumn('quantity_delta', 'bigint CHECK (quantity_delta <> 0)');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->uuid('order_id')->nullable()->index();
            $table->uuid('store_session_expense_id')->nullable()->index();
            $table->uuid('stock_transfer_id')->nullable()->index();
            $table->timestamp('created_at');

            $table->index(['branch_id', 'product_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('branch_inventory');
    }
};
