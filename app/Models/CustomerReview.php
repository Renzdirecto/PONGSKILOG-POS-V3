<?php

namespace App\Models;

use Database\Factories\CustomerReviewFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** @property list<array{path: string, kind: string, width: int, height: int, bytes: int}>|null $images */
#[Fillable(['branch_id', 'submission_key', 'submission_digest', 'rating', 'preferred_name', 'customer_email', 'description', 'purchased_items', 'visit_type', 'visibility', 'status', 'public_consent', 'consented_at', 'notice_version', 'images', 'moderated_by', 'moderated_at'])]
class CustomerReview extends Model
{
    /** @use HasFactory<CustomerReviewFactory> */
    use HasFactory, HasUuids;

    public const NOTICE_VERSION = '2026-10-14-v5';

    /** New submissions only: the column keeps 60 characters so names saved before v1.2.1 Manual QA are never cut. */
    public const PREFERRED_NAME_MAX = 10;

    /** The optional customer email is private contact data: encrypted at rest and never serialized. */
    protected $hidden = ['submission_key', 'submission_digest', 'images', 'customer_email'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rating' => 'integer', 'images' => 'array', 'customer_email' => 'encrypted', 'public_consent' => 'boolean', 'consented_at' => 'immutable_datetime', 'moderated_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @param Builder<CustomerReview> $query */
    #[Scope]
    protected function published(Builder $query): void
    {
        $query->where('visibility', 'public')->where('status', 'approved')->where('public_consent', true)
            ->whereHas('branch', fn (Builder $branch) => $branch->where('status', 'active'));
    }

    public function isPublished(): bool
    {
        return self::query()->published()->whereKey($this->id)->exists();
    }

    /**
     * The public rating summary. It covers every published review, not only the newest ones the landing page lists,
     * and never a pending, rejected or private one.
     *
     * @return array{count: int, average: float|null}
     */
    public static function publishedSummary(): array
    {
        $summary = self::query()->published()->toBase()->selectRaw('COUNT(*) AS total, AVG(rating) AS average')->first();
        $count = (int) ($summary->total ?? 0);

        return ['count' => $count, 'average' => $count > 0 ? round((float) $summary->average, 1) : null];
    }

    /** @return array<string, mixed> */
    public function projection(): array
    {
        return [
            ...$this->only(['id', 'rating', 'preferred_name', 'description', 'purchased_items', 'visit_type']),
            'date' => $this->created_at?->timezone('Asia/Manila')->toDateString(),
            'images' => collect($this->images ?? [])->map(fn (array $image, int $index): array => [
                'url' => route('reviews.images.show', [$this->id, $index], false),
                'kind' => $image['kind'], 'width' => $image['width'], 'height' => $image['height'],
            ])->all(),
        ];
    }
}
