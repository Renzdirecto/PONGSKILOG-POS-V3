<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_qr_sessions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->foreignUuid('active_order_id')->nullable()->unique()->constrained('orders')->restrictOnDelete();
            $table->timestamp('expires_at')->index();
            $table->timestamps();
        });
        Schema::table('orders', function (Blueprint $table): void {
            if (DB::getDriverName() === 'sqlite') {
                /** Native ADD preserves the existing inline money/status checks; a foreign() rebuild would lose them. */
                $table->rawColumn('customer_qr_session_id', 'varchar references customer_qr_sessions(id) on delete restrict')->nullable();
            } else {
                $table->foreignUuid('customer_qr_session_id')->nullable()->constrained()->restrictOnDelete();
            }
            $table->char('public_tracking_id', 64)->nullable()->unique();
            $table->uuid('qr_idempotency_key')->nullable();
            $table->char('qr_intent_hash', 64)->nullable();
            $table->string('table_name_snapshot')->nullable();
            $table->unique(['customer_qr_session_id', 'qr_idempotency_key'], 'orders_qr_intent_unique');
            $table->index(['branch_id', 'source', 'commercial_status', 'submitted_at'], 'orders_qr_queue_index');
        });
        Schema::table('order_item_modifiers', function (Blueprint $table): void {
            $table->uuid('modifier_group_id_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table): void {
            $table->dropColumn('modifier_group_id_snapshot');
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique('orders_qr_intent_unique');
            $table->dropIndex('orders_qr_queue_index');
            $table->dropUnique(['public_tracking_id']);
            if (DB::getDriverName() === 'sqlite') {
                $table->dropColumn('customer_qr_session_id');
            } else {
                $table->dropConstrainedForeignId('customer_qr_session_id');
            }
            $table->dropColumn(['public_tracking_id', 'qr_idempotency_key', 'qr_intent_hash', 'table_name_snapshot']);
        });
        Schema::dropIfExists('customer_qr_sessions');
    }
};
