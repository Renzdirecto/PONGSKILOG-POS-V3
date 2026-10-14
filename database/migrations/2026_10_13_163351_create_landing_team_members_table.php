<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('landing_team_members', function (Blueprint $table) {
            $table->id();
            /** The fixed order of the six cards on the landing page. */
            $table->unsignedTinyInteger('position')->unique();
            $table->string('role_label', 12);
            $table->string('role_title', 60);
            $table->string('name', 60)->nullable();
            $table->string('bio', 120)->nullable();
            /** An upload on object storage (`landing-team/...`) or one of the portraits bundled with the release. */
            $table->string('image_path')->nullable();
            $table->boolean('is_visible')->default(true);
            $table->timestamps();
        });

        /**
         * The six cards of Landing V6 with only what the business supplied: three portraits, names and bios. The other
         * three stay empty and show as marked placeholders until the Owner fills them in Website → Team.
         */
        $members = [
            ['CEO', 'Founder & Chief Executive Officer', null, null, null],
            ['COO', 'Chief Operating Officer', 'Shamarra Faustino', 'Siya ang nag-aayos ng schedule, supplies at takbo ng kusina gabi-gabi.', 'images/landing-v130-a82ec97c.webp'],
            ['CTO', 'Chief Technology Officer', 'Samantha Faustino', 'Masungit na Cashier', 'images/landing-v130-cdf1eb05.webp'],
            ['CFO', 'Chief Financial Officer', 'Sabrina Cheang', 'Ang Batang ina ng adarna street', 'images/landing-v130-c719a715.webp'],
            ['CHEF', 'Head Chef', null, null, null],
            ['CMO', 'Chief Marketing Officer', null, null, null],
        ];
        $now = now();
        foreach ($members as $index => [$label, $title, $name, $bio, $image]) {
            DB::table('landing_team_members')->insert([
                'position' => $index + 1, 'role_label' => $label, 'role_title' => $title, 'name' => $name, 'bio' => $bio,
                'image_path' => $image, 'is_visible' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('landing_team_members');
    }
};
