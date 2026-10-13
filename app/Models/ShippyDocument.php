<?php

namespace App\Models;

use App\Support\ShippyKnowledgeBase;
use Carbon\CarbonInterface;
use Database\Factories\ShippyDocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A Markdown file the Owner uploaded as reference notes for Shippy (v1.3.0). Its text is data Shippy may quote, never
 * instructions, and it never leaves the server as a file: Website → Shippy lists only the name, size and state.
 *
 * @property int $id
 * @property string $filename
 * @property string $content
 * @property int $bytes
 * @property int $sections
 * @property bool $is_enabled
 * @property CarbonInterface|null $updated_at
 */
#[Fillable(['filename', 'content', 'bytes', 'sections', 'is_enabled'])]
class ShippyDocument extends Model
{
    /** @use HasFactory<ShippyDocumentFactory> */
    use HasFactory;

    /** The most documents kept and the largest one accepted. */
    public const MAX_DOCUMENTS = 10;

    public const MAX_KILOBYTES = 100;

    protected $hidden = ['content'];

    /** Shippy reads the documents from the cache, so every committed change drops what was prepared. */
    protected static function booted(): void
    {
        static::saved(fn () => DB::afterCommit(ShippyKnowledgeBase::forget(...)));
        static::deleted(fn () => DB::afterCommit(ShippyKnowledgeBase::forget(...)));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['bytes' => 'integer', 'sections' => 'integer', 'is_enabled' => 'boolean'];
    }

    /**
     * The row Website → Shippy lists. The text itself is never part of it.
     *
     * @return array{id: int, filename: string, bytes: int, sections: int, enabled: bool, updated_at: string|null}
     */
    public function row(): array
    {
        return [
            'id' => $this->id, 'filename' => $this->filename, 'bytes' => $this->bytes, 'sections' => $this->sections,
            'enabled' => $this->is_enabled, 'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
