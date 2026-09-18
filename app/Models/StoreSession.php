<?php

namespace App\Models;

use App\Enums\StoreSessionStatus;
use Database\Factories\StoreSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'branch_id', 'status', 'opened_by_user_id', 'opened_at',
    'opening_cash_amount', 'opening_cashless_amount',
    'closing_cash_amount', 'closing_cashless_amount',
    'expected_cash_amount', 'expected_cashless_amount',
    'cash_variance', 'cashless_variance', 'closing_note',
    'closed_by_user_id', 'closed_at',
])]
class StoreSession extends Model
{
    /** @use HasFactory<StoreSessionFactory> */
    use HasFactory, HasUuids;

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
}
