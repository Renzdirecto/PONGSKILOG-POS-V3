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
        Schema::table('void_authorization_settings', function (Blueprint $table): void {
            $table->string('scope', 40)->default('global')->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('void_authorization_settings', function (Blueprint $table): void {
            $table->dropUnique(['scope']);
            $table->dropColumn('scope');
        });
    }
};
