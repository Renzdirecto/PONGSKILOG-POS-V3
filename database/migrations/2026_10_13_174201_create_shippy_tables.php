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
        /** The one switch of the landing page's Shippy chat (Website → Shippy). The API key stays in the environment. */
        Schema::create('shippy_settings', function (Blueprint $table) {
            $table->id();
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
        DB::table('shippy_settings')->insert(['is_enabled' => true, 'created_at' => now(), 'updated_at' => now()]);

        /**
         * Shippy's usage as one row of counters per Asia/Manila date (App\Support\ShippyUsage). It is what the daily
         * quota and the Owner's figures read; no message text and nothing about the guest is stored.
         */
        Schema::create('shippy_usage', function (Blueprint $table) {
            $table->date('used_on')->primary();
            $table->unsignedBigInteger('messages')->default(0);
            $table->unsignedBigInteger('requests')->default(0);
            $table->unsignedBigInteger('errors')->default(0);
            $table->unsignedBigInteger('input_tokens')->default(0);
            $table->unsignedBigInteger('output_tokens')->default(0);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shippy_usage');
        Schema::dropIfExists('shippy_settings');
    }
};
