<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('store_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->enum('status', ['open', 'closed']);
            $table->foreignId('opened_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('opened_at');

            /** Inline checks work on both PostgreSQL and SQLite without rebuilding the table. */
            $table->rawColumn('opening_cash_amount', 'numeric(14, 2) CHECK (opening_cash_amount >= 0)');
            $table->rawColumn('opening_cashless_amount', 'numeric(14, 2) CHECK (opening_cashless_amount >= 0)');
            $table->rawColumn('closing_cash_amount', 'numeric(14, 2) CHECK (closing_cash_amount >= 0)')->nullable();
            $table->rawColumn('closing_cashless_amount', 'numeric(14, 2) CHECK (closing_cashless_amount >= 0)')->nullable();
            $table->rawColumn('expected_cash_amount', 'numeric(14, 2) CHECK (expected_cash_amount >= 0)')->nullable();
            $table->rawColumn('expected_cashless_amount', 'numeric(14, 2) CHECK (expected_cashless_amount >= 0)')->nullable();
            $table->decimal('cash_variance', 14, 2)->nullable();
            $table->decimal('cashless_variance', 14, 2)->nullable();
            $table->text('closing_note')->nullable();
            $table->foreignId('closed_by_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'opened_at']);
            $table->index(['branch_id', 'status']);
        });

        DB::statement("CREATE UNIQUE INDEX store_sessions_one_open_per_branch_unique ON store_sessions (branch_id) WHERE status = 'open'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('store_sessions');
    }
};
