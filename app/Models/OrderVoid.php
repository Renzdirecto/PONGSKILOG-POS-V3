<?php

namespace App\Models;

use Database\Factories\OrderVoidFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $created_at */
#[Fillable(['branch_id', 'store_session_id', 'order_id', 'initiated_by_user_id', 'authorized_by_user_id', 'reason_code', 'reason_label', 'reason_text', 'authorization_method', 'idempotency_key'])]
class OrderVoid extends Model
{
    /** @use HasFactory<OrderVoidFactory> */
    use HasFactory, HasUuids;

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
    public function initiatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'initiated_by_user_id');
    }

    /** @return BelongsTo<User, $this> */
    public function authorizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'authorized_by_user_id');
    }
}
