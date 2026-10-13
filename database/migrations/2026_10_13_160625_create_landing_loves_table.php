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
        Schema::create('landing_loves', function (Blueprint $table) {
            /**
             * Tap-to-love counted per Asia/Manila clock hour (App\Support\LandingLove). The landing page total and the
             * Owner's Heart Taps both read these rows, and nothing about the visitor who tapped is stored.
             */
            $table->date('occurred_on');
            $table->unsignedTinyInteger('hour');
            $table->unsignedBigInteger('taps')->default(0);
            $table->primary(['occurred_on', 'hour']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_loves');
    }
};
