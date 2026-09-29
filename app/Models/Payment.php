<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Database\Factories\PaymentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property PaymentMethod $method
 * @property Carbon $paid_at
 */
#[Fillable(['branch_id', 'store_session_id', 'order_id', 'method', 'amount', 'amount_received', 'change_amount', 'created_by_user_id', 'idempotency_key', 'payment_group_id', 'payment_context', 'paid_at'])]
class Payment extends Model
{
    /** @use HasFactory<PaymentFactory> */
    use HasFactory, HasUuids;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['method' => PaymentMethod::class, 'amount' => 'decimal:2', 'amount_received' => 'decimal:2', 'change_amount' => 'decimal:2', 'paid_at' => 'datetime'];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<StoreSession, $this> */
    public function storeSession(): BelongsTo
    {
        return $this->belongsTo(StoreSession::class);
    }

    /** @return BelongsTo<Order, $this> */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasOne<PaymentInvoiceProof, $this> */
    public function invoiceProof(): HasOne
    {
        return $this->hasOne(PaymentInvoiceProof::class);
    }
}
