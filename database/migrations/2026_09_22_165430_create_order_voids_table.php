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
        Schema::create('order_voids', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('store_session_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('order_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('initiated_by_user_id')->constrained('users')->restrictOnDelete();
            $table->foreignId('authorized_by_user_id')->constrained('users')->restrictOnDelete();
            $table->string('reason_code', 80);
            $table->string('reason_label', 120);
            $table->text('reason_text')->nullable();
            $table->string('authorization_method', 40);
            $table->uuid('idempotency_key')->unique();
            $table->timestamps();

            $table->index(['branch_id', 'created_at']);
            $table->index(['initiated_by_user_id', 'created_at']);
            $table->index(['authorized_by_user_id', 'created_at']);
            $table->index(['reason_code', 'created_at']);
        });

        Schema::table('audit_logs', function (Blueprint $table) {
            $table->index(['user_id', 'created_at']);
            $table->index(['module', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropIndex(['module', 'created_at']);
            $table->dropIndex(['action', 'created_at']);
        });

        Schema::dropIfExists('order_voids');
    }
};
