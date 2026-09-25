<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * One row per browser Web Push subscription (PWA Phase 1). The endpoint is a secret capability URL, so it and the
     * browser's P-256 key and auth secret are stored encrypted. `endpoint_hash` (SHA-256 of the endpoint) is the
     * deterministic unique key: two tabs or a repeated Enable never create a second row for one browser, and a browser
     * signed into another account moves its single row to that account. `device_hash` identifies the browser's
     * HttpOnly device cookie, so a logout can remove that device's subscription server-side. Accounts are
     * deactivated, never deleted; the cascade only removes subscriptions of a genuinely removed row.
     */
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('endpoint_hash', 64)->unique();
            $table->char('device_hash', 64)->nullable();
            $table->text('endpoint');
            $table->text('public_key');
            $table->text('auth_token');
            $table->rawColumn('content_encoding', "varchar(16) NOT NULL DEFAULT 'aes128gcm' CHECK (content_encoding IN ('aes128gcm', 'aesgcm'))");
            $table->timestamps();

            $table->index('user_id');
            $table->index('device_hash');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
