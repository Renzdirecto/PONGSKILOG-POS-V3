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
        Schema::table('branches', function (Blueprint $table) {
            /**
             * Phase 20 Receipt Settings: which optional receipt blocks show and in what order, header text, custom text
             * rows and the separator style (App\Support\ReceiptLayout). Null keeps the standard receipt.
             */
            $table->json('receipt_layout')->nullable();
            /** Phase 20: an optional store photo for Branch Management (object storage path, never binary data). */
            $table->string('image_path', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('branches', function (Blueprint $table) {
            $table->dropColumn(['receipt_layout', 'image_path']);
        });
    }
};
