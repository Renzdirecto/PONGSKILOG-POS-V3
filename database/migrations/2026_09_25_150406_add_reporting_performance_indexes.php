<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 19: indexes for real queries that sorted or scanned a growing table. Additive only: no row is changed, and each
 * index backs one existing query shape (read backwards for newest-first ordering).
 */
return new class extends Migration
{
    /** @var array<string, array<string, list<string>>> table => index name => columns */
    private const INDEXES = [
        /** Audit Trail and the Executive Dashboard browse `ORDER BY created_at DESC, id DESC` with no leading filter. */
        'audit_logs' => ['audit_logs_created_at_id_index' => ['created_at', 'id']],
        /** All Branches recent transactions (Dashboard) and Transaction History: `ORDER BY committed_at DESC, id DESC`. */
        'orders' => ['orders_committed_at_id_index' => ['committed_at', 'id']],
        /** The notification list: one recipient's rows `ORDER BY created_at DESC, id DESC`. */
        'notifications' => ['notifications_notifiable_created_at_index' => ['notifiable_type', 'notifiable_id', 'created_at', 'id']],
        /** Unindexed foreign keys read by Operations › Purchases (items eager load, per-session purchases). */
        'pamamalengke_purchase_items' => ['pamamalengke_purchase_items_purchase_index' => ['pamamalengke_purchase_id']],
        'pamamalengke_purchases' => ['pamamalengke_purchases_store_session_index' => ['store_session_id']],
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach ($indexes as $name => $columns) {
                    $blueprint->index($columns, $name);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        foreach (self::INDEXES as $table => $indexes) {
            Schema::table($table, function (Blueprint $blueprint) use ($indexes) {
                foreach (array_keys($indexes) as $name) {
                    $blueprint->dropIndex($name);
                }
            });
        }
    }
};
