<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user permission exceptions on top of the Role baseline. No row means INHERIT; a row is an explicit ALLOW or
     * DENY. Accounts are deactivated, never deleted, so the cascade only removes exceptions of a genuinely removed row.
     */
    public function up(): void
    {
        Schema::create('user_permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $table->rawColumn('effect', "varchar(5) NOT NULL CHECK (effect IN ('allow', 'deny'))");
            $table->timestamps();

            $table->unique(['user_id', 'permission_id']);
            $table->index('permission_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('user_permission_overrides');
    }
};
