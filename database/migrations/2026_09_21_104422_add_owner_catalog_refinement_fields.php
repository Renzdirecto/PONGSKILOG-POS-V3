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
        Schema::table('categories', function (Blueprint $table) {
            $table->string('icon_key', 32)->nullable()->after('name');
        });

        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->string('semantic_role', 32)->nullable()->after('name');
        });

        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->string('semantic_role_snapshot', 32)->nullable()->after('group_name_snapshot');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('order_item_modifiers', function (Blueprint $table) {
            $table->dropColumn('semantic_role_snapshot');
        });

        Schema::table('modifier_groups', function (Blueprint $table) {
            $table->dropColumn('semantic_role');
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->dropColumn('icon_key');
        });
    }
};
