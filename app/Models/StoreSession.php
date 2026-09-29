<?php

namespace App\Models;

use App\Enums\StoreSessionStatus;
use Database\Factories\StoreSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property Carbon $opened_at
 * @property string $id
 * @property string $opening_cash_amount
 * @property string $opening_cashless_amount
 * @property string|null $closing_cash_amount
 * @property string|null $closing_cashless_amount
 * @property string|null $expected_cash_amount
 * @property string|null $expected_cashless_amount
 * @property string|null $cash_variance
 * @property string|null $cashless_variance
 * @property string|null $closing_note
 * @property array<string, mixed>|null $reconciliation_snapshot
 * @property int|null $closed_by_user_id
 * @property Carbon|null $closed_at
 * @property StoreSessionStatus $status
 */
#[Fillable([
    'branch_id', 'status', 'opened_by_user_id', 'opened_at',
    'opening_cash_amount', 'opening_cashless_amount',
    'closing_cash_amount', 'closing_cashless_amount',
    'expected_cash_amount', 'expected_cashless_amount',
    'cash_variance', 'cashless_variance', 'closing_note', 'reconciliation_snapshot',
    'closed_by_user_id', 'closed_at',
])]
class StoreSession extends Model
{
    /** @use HasFactory<StoreSessionFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        /** A closed session's reconciliation snapshot is historical truth; there is no reopen or correction path. */
        static::updating(function (StoreSession $session): void {
            if ($session->getRawOriginal('status') === StoreSessionStatus::Closed->value) {
                throw new \LogicException('A closed Store Session cannot be changed.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => StoreSessionStatus::class,
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'opening_cash_amount' => 'decimal:2',
            'opening_cashless_amount' => 'decimal:2',
            'closing_cash_amount' => 'decimal:2',
            'closing_cashless_amount' => 'decimal:2',
            'expected_cash_amount' => 'decimal:2',
            'expected_cashless_amount' => 'decimal:2',
            'cash_variance' => 'decimal:2',
            'cashless_variance' => 'decimal:2',
            'reconciliation_snapshot' => 'array',
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<User, $this> */
    public function openedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @return HasMany<StoreSessionExpense, $this> */
    public function expenses(): HasMany
    {
        return $this->hasMany(StoreSessionExpense::class);
    }
}
