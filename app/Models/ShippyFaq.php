<?php

namespace App\Models;

use App\Support\ShippyKnowledgeBase;
use Database\Factories\ShippyFaqFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * One question the Owner answered for Shippy in Website → Shippy (v1.3.0). While it is switched on, a guest who asks
 * it, in the Owner's wording or one of the alternatives, gets this answer as written and Gemini is not asked.
 *
 * @property int $id
 * @property string $question
 * @property string $answer
 * @property string|null $category
 * @property list<string> $alternatives
 * @property bool $is_enabled
 */
#[Fillable(['question', 'answer', 'category', 'alternatives', 'is_enabled'])]
class ShippyFaq extends Model
{
    /** @use HasFactory<ShippyFaqFactory> */
    use HasFactory;

    /** The most FAQs kept, and the most other wordings of one question. */
    public const MAX_FAQS = 100;

    public const MAX_ALTERNATIVES = 8;

    public const MAX_CATEGORY = 40;

    /** Shippy reads the FAQs from the cache, so every committed change drops what was prepared. */
    protected static function booted(): void
    {
        static::saved(fn () => DB::afterCommit(ShippyKnowledgeBase::forget(...)));
        static::deleted(fn () => DB::afterCommit(ShippyKnowledgeBase::forget(...)));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['alternatives' => 'array', 'is_enabled' => 'boolean'];
    }

    /**
     * The row Website → Shippy lists.
     *
     * @return array{id: int, question: string, answer: string, category: string|null, alternatives: list<string>, enabled: bool}
     */
    public function row(): array
    {
        return [
            'id' => $this->id, 'question' => $this->question, 'answer' => $this->answer, 'category' => $this->category,
            'alternatives' => $this->alternatives, 'enabled' => $this->is_enabled,
        ];
    }
}
