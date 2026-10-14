<?php

namespace Database\Factories;

use App\Models\ShippyDocument;
use App\Support\ShippyMarkdown;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShippyDocument>
 */
class ShippyDocumentFactory extends Factory
{
    /**
     * A switched-on document with one section.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'filename' => fake()->unique()->slug(2).'.md',
            'content' => "# Notes\n\n".fake()->sentence(8),
            'bytes' => fn (array $document): int => strlen((string) $document['content']),
            'sections' => fn (array $document): int => count(ShippyMarkdown::sections((string) $document['content'], (string) $document['filename'])),
            'is_enabled' => true,
        ];
    }

    public function disabled(): static
    {
        return $this->state(['is_enabled' => false]);
    }
}
