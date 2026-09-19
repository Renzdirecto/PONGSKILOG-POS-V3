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
        Schema::create('order_number_counters', function (Blueprint $table) {
            $table->foreignUuid('branch_id')->primary()->constrained()->restrictOnDelete();
            $table->rawColumn('next_number', 'bigint CHECK (next_number > 0)')->default(1001);
            $table->timestamps();
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('reference_number', 64)->nullable()->unique()->after('order_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropUnique(['reference_number']);
            $table->dropColumn('reference_number');
        });

        Schema::dropIfExists('order_number_counters');
    }
};
