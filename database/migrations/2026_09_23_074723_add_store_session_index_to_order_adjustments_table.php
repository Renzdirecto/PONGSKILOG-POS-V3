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
        Schema::table('order_adjustments', function (Blueprint $table): void {
            /** Close Store aggregates corrections for one session without scanning all correction history. */
            $table->index(['store_session_id', 'order_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_adjustments', function (Blueprint $table): void {
            $table->dropIndex(['store_session_id', 'order_id']);
        });
    }
};
