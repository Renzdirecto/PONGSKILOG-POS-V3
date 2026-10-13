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
        Schema::create('customer_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('branch_id')->constrained()->restrictOnDelete();
            $table->uuid('submission_key')->unique();
            $table->string('submission_digest', 64)->unique();
            $table->unsignedTinyInteger('rating');
            $table->string('preferred_name', 60);
            $table->string('description', 200);
            $table->string('purchased_items', 200)->nullable();
            $table->enum('visit_type', ['dine_in', 'takeout'])->nullable();
            $table->enum('visibility', ['public', 'private']);
            $table->enum('status', ['pending', 'approved', 'rejected'])->default('pending');
            $table->boolean('public_consent')->default(false);
            $table->timestamp('consented_at');
            $table->string('notice_version', 32);
            $table->json('images')->nullable();
            $table->foreignId('moderated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('moderated_at')->nullable();
            $table->timestamps();
            $table->index(['branch_id', 'visibility', 'status', 'created_at']);
            $table->index(['visibility', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('customer_reviews');
    }
};
