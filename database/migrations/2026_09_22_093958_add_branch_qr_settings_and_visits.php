<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('branches', function (Blueprint $table): void {
            $table->string('kiosk_code', 32)->nullable()->unique();
            $table->boolean('qr_ordering_enabled')->default(true);
            $table->string('facebook_url', 500)->nullable();
            $table->string('website_url', 500)->nullable();
            $table->string('receipt_name', 150)->nullable();
            $table->string('receipt_address', 500)->nullable();
            $table->string('receipt_contact', 100)->nullable();
            $table->string('receipt_footer', 250)->nullable();
            $table->boolean('receipt_show_logo')->default(true);
        });
        DB::table('branches')->update(['kiosk_code' => DB::raw('code')]);
        Schema::create('customer_qr_visits', function (Blueprint $table): void {
            $table->id();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('customer_qr_session_id')->constrained()->restrictOnDelete();
            $table->timestamp('visited_at');
            $table->index(['branch_id', 'visited_at']);
            $table->index(['customer_qr_session_id', 'visited_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_qr_visits');
        Schema::table('branches', function (Blueprint $table): void {
            $table->dropUnique(['kiosk_code']);
            $table->dropColumn(['kiosk_code', 'qr_ordering_enabled', 'facebook_url', 'website_url', 'receipt_name', 'receipt_address', 'receipt_contact', 'receipt_footer', 'receipt_show_logo']);
        });
    }
};
