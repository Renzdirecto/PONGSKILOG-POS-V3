<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $this->nullableIdentity(true);
        Schema::create('customer_qr_order_counters', function (Blueprint $table): void {
            $table->foreignUuid('store_session_id')->primary()->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('next_number')->default(1);
        });
        Schema::create('order_reference_counters', function (Blueprint $table): void {
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->date('business_date');
            $table->unsignedBigInteger('next_number')->default(1);
            $table->primary(['branch_id', 'business_date']);
        });
        Schema::table('orders', function (Blueprint $table): void {
            $table->unsignedBigInteger('qr_sequence')->nullable();
            $table->timestamp('preparing_at')->nullable();
            $table->timestamp('ready_at')->nullable();
            $table->unique(['store_session_id', 'qr_sequence']);
        });
    }

    public function down(): void
    {
        if (DB::table('orders')->whereNull('order_number')->exists()) {
            throw new RuntimeException('Cannot roll back while provisional QR orders exist. Preserve their history and roll forward.');
        }
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropUnique(['store_session_id', 'qr_sequence']);
            $table->dropColumn(['qr_sequence', 'preparing_at', 'ready_at']);
        });
        Schema::dropIfExists('order_reference_counters');
        Schema::dropIfExists('customer_qr_order_counters');
        $this->nullableIdentity(false);
    }

    private function nullableIdentity(bool $nullable): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            if (! $nullable) {
                DB::statement('ALTER TABLE orders DROP CONSTRAINT orders_provisional_identity_check');
            }
            DB::statement('ALTER TABLE orders ALTER COLUMN order_number '.($nullable ? 'DROP' : 'SET').' NOT NULL');
            if ($nullable) {
                DB::statement("ALTER TABLE orders ADD CONSTRAINT orders_provisional_identity_check CHECK (order_number IS NOT NULL OR (source = 'customer_qr' AND committed_at IS NULL))");
            }

            return;
        }
        /** Preserve the original inline CHECK constraints, foreign keys and indexes during SQLite rebuild. */
        $definition = DB::selectOne("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = 'orders'")->sql;
        if (! is_string($definition)) {
            throw new RuntimeException('Missing SQLite order table definition.');
        }
        $indexes = DB::select("SELECT sql FROM sqlite_master WHERE type = 'index' AND tbl_name = 'orders' AND sql IS NOT NULL");
        $definition = str_replace('"orders"', '"orders_identity_rebuild"', $definition);
        $provisional = '"order_number" varchar CHECK (order_number IS NOT NULL OR (source = \'customer_qr\' AND committed_at IS NULL))';
        $definition = $nullable
            ? str_replace('"order_number" varchar not null', $provisional, $definition)
            : str_replace($provisional, '"order_number" varchar not null', $definition);
        DB::statement('PRAGMA foreign_keys = OFF');
        try {
            DB::transaction(function () use ($definition, $indexes): void {
                DB::statement($definition);
                DB::statement('INSERT INTO orders_identity_rebuild SELECT * FROM orders');
                DB::statement('DROP TABLE orders');
                DB::statement('ALTER TABLE orders_identity_rebuild RENAME TO orders');
                foreach ($indexes as $index) {
                    DB::statement($index->sql);
                }
            });
        } finally {
            DB::statement('PRAGMA foreign_keys = ON');
        }
    }
};
