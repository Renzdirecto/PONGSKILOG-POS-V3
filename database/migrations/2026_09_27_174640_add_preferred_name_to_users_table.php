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
         * Phase 20: the display-oriented name a staff member chooses (receipts, greetings, shells). The full `name`
         * stays the admin-managed identity; audit and security always identify the account itself.
         */
        Schema::table('users', function (Blueprint $table) {
            $table->string('preferred_name', 60)->nullable()->after('name');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('preferred_name');
        });
    }
};
