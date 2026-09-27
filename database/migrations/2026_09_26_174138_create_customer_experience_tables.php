<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Phase 19.6 Customer Experience Expansion (additive only).
     *
     * - `customer_screens`: one customer-facing display device, identified by the SHA-256 of its random HttpOnly device
     *   cookie. It is paired to one Branch POS station (the SHA-256 of that station's local installation id), never to
     *   a cashier account; `(branch_id, station_hash)` is unique, so a station drives at most one screen. The selected
     *   persistent mode lives here; the live cart and the order takeover are ephemeral cache state, never rows.
     * - `customer_screen_media`: Branch-owned advertisement images/videos (server-generated storage paths only).
     * - `order_pickup_tokens`: the public pickup capability of one committed Take Out order. Lookup is by the token's
     *   SHA-256; the raw token is kept only encrypted so the paired screen can render the QR again. Buzz counters and
     *   the last idempotency key are guarded by this row's lock.
     * - `pickup_push_subscriptions`: at most one customer Web Push endpoint per pickup token, encrypted at rest and
     *   completely separate from staff `push_subscriptions`.
     */
    public function up(): void
    {
        Schema::create('customer_screens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->char('token_hash', 64)->unique();
            $table->char('channel_key', 40)->unique();
            $table->char('pairing_code_hash', 64)->nullable()->unique();
            $table->timestamp('pairing_code_expires_at')->nullable();
            $table->foreignUuid('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->char('station_hash', 64)->nullable();
            $table->foreignId('paired_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paired_at')->nullable();
            $table->rawColumn('mode', "varchar(20) NOT NULL DEFAULT 'ads' CHECK (mode IN ('ads', 'menu', 'customer_display'))");
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['branch_id', 'station_hash']);
            $table->index('updated_at');
        });

        Schema::create('customer_screen_media', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->rawColumn('media_type', "varchar(10) NOT NULL CHECK (media_type IN ('image', 'video'))");
            $table->string('label', 80);
            $table->string('path', 255);
            $table->string('mime_type', 40);
            $table->unsignedInteger('size_bytes');
            $table->rawColumn('duration_seconds', 'smallint NOT NULL CHECK (duration_seconds BETWEEN 1 AND 120)');
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['branch_id', 'is_active', 'sort_order']);
        });

        Schema::create('order_pickup_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignUuid('branch_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->text('token_ciphertext');
            $table->char('channel_key', 40)->unique();
            $table->timestamp('expires_at');
            $table->rawColumn('buzz_count', 'smallint NOT NULL DEFAULT 0 CHECK (buzz_count >= 0)');
            $table->timestamp('last_buzzed_at')->nullable();
            $table->uuid('last_buzz_key')->nullable();
            $table->timestamps();

            $table->index(['branch_id', 'expires_at']);
            $table->index('expires_at');
        });

        Schema::create('pickup_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('order_pickup_token_id')->unique()->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->index();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->rawColumn('content_encoding', "varchar(16) NOT NULL DEFAULT 'aes128gcm' CHECK (content_encoding IN ('aes128gcm', 'aesgcm'))");
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pickup_push_subscriptions');
        Schema::dropIfExists('order_pickup_tokens');
        Schema::dropIfExists('customer_screen_media');
        Schema::dropIfExists('customer_screens');
    }
};
