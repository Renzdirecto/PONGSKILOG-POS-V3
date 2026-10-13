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
        /**
         * Team members can now be added, deleted and reordered (Website → Team). Reordering rewrites every position in
         * one transaction, which a unique position would refuse halfway; the six existing rows are left as they are.
         */
        Schema::table('landing_team_members', function (Blueprint $table) {
            $table->dropUnique(['position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('landing_team_members', function (Blueprint $table) {
            $table->unique('position');
        });
    }
};
