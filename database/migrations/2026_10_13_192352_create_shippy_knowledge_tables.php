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
        /** Questions the Owner answered once in Website → Shippy; Shippy gives that answer without asking Gemini. */
        Schema::create('shippy_faqs', function (Blueprint $table) {
            $table->id();
            $table->string('question', 200);
            $table->text('answer');
            $table->string('category', 40)->nullable();
            /** Other ways a guest may word the same question. */
            $table->json('alternatives');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });

        /**
         * Markdown files the Owner uploaded as Shippy's reference notes. The text lives here and nowhere else: no
         * route serves it, and Shippy reads only the sections of the documents that are switched on.
         */
        Schema::create('shippy_documents', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 120)->unique();
            $table->text('content');
            $table->unsignedInteger('bytes');
            $table->unsignedSmallInteger('sections');
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('shippy_documents');
        Schema::dropIfExists('shippy_faqs');
    }
};
