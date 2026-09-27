<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 19.6 manual-QA fixes (additive only): the Branch's Customer Screen settings — how long the successful-order
     * confirmation stays up for Dine In and Take Out (3–15 s, default 5 s, validated by the settings endpoint) and the
     * optional Maps link shown on the customer's pickup page next to the existing Facebook and Website links.
     */
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->unsignedTinyInteger('customer_screen_dine_in_success_seconds')->default(5);
            $table->unsignedTinyInteger('customer_screen_take_out_success_seconds')->default(5);
            $table->string('maps_url', 500)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['customer_screen_dine_in_success_seconds', 'customer_screen_take_out_success_seconds', 'maps_url']);
        });
    }
};
