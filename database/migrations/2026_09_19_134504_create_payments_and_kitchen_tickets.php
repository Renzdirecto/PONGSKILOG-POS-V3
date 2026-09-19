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
        Schema::create('payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->enum('method', ['cash', 'cashless']);
            $table->rawColumn('amount', 'numeric(14,2) CHECK (amount >= 0)');
            $table->rawColumn('amount_received', 'numeric(14,2) CHECK (amount_received >= 0)')->nullable();
            $table->rawColumn('change_amount', 'numeric(14,2) CHECK (change_amount >= 0)')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('idempotency_key', 45)->unique();
            $table->timestamp('paid_at');
            $table->timestamps();
            $table->index('order_id');
            $table->index(['branch_id', 'paid_at']);
            $table->index('store_session_id');
        });
        Schema::create('kitchen_tickets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('status', ['kitchen', 'preparing', 'ready', 'done']);
            $table->timestamps();
            $table->index(['branch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('kitchen_tickets');
        Schema::dropIfExists('payments');
    }
};
