<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * The one switch of the landing page's Shippy chat (v1.3.0), turned on or off in Website → Shippy. The Gemini API key
 * is environment configuration and is never stored here.
 *
 * @property int $id
 * @property bool $is_enabled
 */
#[Fillable(['is_enabled'])]
class ShippySetting extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_enabled' => 'boolean'];
    }

    /** The single settings row, which the migration creates. */
    public static function current(): self
    {
        return self::query()->orderBy('id')->first() ?? self::query()->create(['is_enabled' => true]);
    }
}
