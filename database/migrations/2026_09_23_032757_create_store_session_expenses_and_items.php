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
        Schema::create('store_session_expenses', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->string('description', 150);
            $table->rawColumn('amount', 'numeric(14, 2) CHECK (amount > 0)');
            $table->enum('payment_source', ['cash', 'cashless']);
            $table->text('note')->nullable();
            $table->string('receipt_disk', 40)->nullable();
            $table->string('receipt_image_path')->nullable();
            $table->string('receipt_original_name')->nullable();
            $table->string('receipt_mime_type', 100)->nullable();
            $table->unsignedBigInteger('receipt_size_bytes')->nullable();
            $table->string('receipt_sha256', 64)->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('intent_hash', 64);
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
            $table->index(['store_session_id', 'created_at']);
            $table->index(['store_session_id', 'payment_source']);
        });

        Schema::create('store_session_expense_items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('store_session_expense_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->rawColumn('quantity', 'bigint CHECK (quantity > 0)');
            $table->timestamps();

            $table->index(['product_id', 'created_at']);
        });

        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->foreign('store_session_expense_id', 'inventory_movements_expense_foreign')
                    ->references('id')->on('store_session_expenses')->restrictOnDelete();
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'sqlite') {
            Schema::table('inventory_movements', function (Blueprint $table) {
                $table->dropForeign('inventory_movements_expense_foreign');
            });
        }

        Schema::dropIfExists('store_session_expense_items');
        Schema::dropIfExists('store_session_expenses');
    }
};
