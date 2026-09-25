<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Role metadata for Custom Roles. `name` stays the stable machine key (system roles keep theirs; a Custom Role gets
     * `custom_{id}`), `label` is the human display name, `is_system` marks the five canonical roles, `scope` says WHERE a
     * role works (Branch or business-wide) and `archived_at` retires an unassigned Custom Role without deleting its
     * audit meaning. Active display names are unique ignoring case.
     */
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            $table->string('label', 60)->nullable()->after('name');
            $table->boolean('is_system')->default(false)->after('label');
            $table->rawColumn('scope', "varchar(16) CHECK (scope IN ('branch', 'business'))")->nullable()->after('is_system');
            $table->timestamp('archived_at')->nullable()->after('scope');
        });

        foreach ([
            'super_admin' => ['Super Admin', 'business'],
            'owner' => ['Owner', 'business'],
            'cashier' => ['Cashier', 'branch'],
            'kitchen_staff' => ['Kitchen Staff', 'branch'],
            'cashier_kitchen' => ['Cashier + Kitchen', 'branch'],
        ] as $name => [$label, $scope]) {
            DB::table('roles')->where('name', $name)->update(['label' => $label, 'is_system' => true, 'scope' => $scope]);
        }
        DB::table('roles')->whereNull('label')->update(['label' => DB::raw('name')]);

        DB::statement('CREATE UNIQUE INDEX roles_active_label_unique ON roles (LOWER(label)) WHERE archived_at IS NULL');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS roles_active_label_unique');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn(['label', 'is_system', 'scope', 'archived_at']);
        });
    }
};
