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
        Schema::create('website_events', function (Blueprint $table) {
            $table->id();
            $table->string('event', 24);
            /** A keyed hash of the visitor's session that changes every day; never an IP address or a stable identifier. */
            $table->char('visitor', 64)->nullable();
            /** The Asia/Manila calendar date of the event. */
            $table->date('occurred_on');
            $table->timestamp('created_at');
            $table->index(['occurred_on', 'event']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('website_events');
    }
};
