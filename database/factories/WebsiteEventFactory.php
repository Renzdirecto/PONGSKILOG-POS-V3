<?php

namespace Database\Factories;

use App\Models\WebsiteEvent;
use App\Support\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<WebsiteEvent>
 */
class WebsiteEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'event' => 'page_view',
            'visitor' => hash('sha256', Str::random(32)),
            'occurred_on' => now(ReportPeriod::TIMEZONE)->toDateString(),
            'created_at' => now(),
        ];
    }

    /** An event at one Asia/Manila moment, e.g. `at('2026-10-13 19:30')`. */
    public function at(string $manila): static
    {
        $moment = CarbonImmutable::parse($manila, ReportPeriod::TIMEZONE);

        return $this->state(['occurred_on' => $moment->toDateString(), 'created_at' => $moment->utc()]);
    }
}
