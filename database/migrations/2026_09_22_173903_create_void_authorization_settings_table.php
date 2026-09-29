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
        Schema::create('void_authorization_settings', function (Blueprint $table) {
            $table->id();
            $table->string('pin_hash');
            $table->foreignId('configured_by_user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('configured_at');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('void_authorization_settings');
    }
};
