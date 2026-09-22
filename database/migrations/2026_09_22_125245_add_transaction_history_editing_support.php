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
        Schema::table('orders', function (Blueprint $table) {
            $table->rawColumn('original_total', 'numeric(14, 2) CHECK (original_total >= 0)')->nullable()->after('total');
            $table->timestamp('edited_at')->nullable()->after('voided_at');
            $table->index(['branch_id', 'committed_at']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->uuid('payment_group_id')->nullable()->after('idempotency_key')->index();
            $table->string('payment_context', 40)->nullable()->after('payment_group_id')->index();
        });

        Schema::create('order_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->string('type', 50);
            $table->rawColumn('amount', 'numeric(14, 2) CHECK (amount > 0)');
            $table->text('reason')->nullable();
            $table->foreignId('created_by_user_id')->constrained('users')->restrictOnDelete();
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();
            $table->index(['order_id', 'created_at']);
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('module', 80);
            $table->string('action', 100);
            $table->string('auditable_type');
            $table->string('auditable_id', 64);
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->json('metadata')->nullable();
            $table->uuid('idempotency_key')->nullable()->unique();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['auditable_type', 'auditable_id', 'created_at']);
            $table->index(['branch_id', 'created_at']);
        });

        Schema::create('payment_invoice_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('payment_id')->unique()->constrained()->restrictOnDelete();
            $table->string('disk', 40);
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size_bytes');
            $table->foreignId('uploaded_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['branch_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('payment_invoice_proofs');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('order_adjustments');

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['payment_group_id', 'payment_context']);
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['branch_id', 'committed_at']);
            $table->dropColumn(['original_total', 'edited_at']);
        });
    }
};
