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
        /** Inventory-only Store Session deductions: no money, attributable to the session through the movement. */
        Schema::create('store_session_inventory_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('product_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('inventory_movement_id')->unique()->constrained()->restrictOnDelete();
            $table->enum('reason_code', ['complimentary', 'wastage', 'damaged', 'staff_meal', 'other']);
            $table->rawColumn('quantity', 'integer CHECK (quantity > 0)');
            $table->text('note')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->string('intent_hash', 64);
            $table->timestamps();
            $table->index(['store_session_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_session_inventory_adjustments');
    }
};
