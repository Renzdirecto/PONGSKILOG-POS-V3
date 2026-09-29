<?php

namespace App\Models;

use App\Enums\CommercialStatus;
use App\Enums\KitchenStatus;
use App\Enums\OrderSource;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTerm;
use Database\Factories\OrderFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string|null $order_number
 * @property string|null $reference_number
 * @property int|null $qr_sequence
 * @property Carbon|null $preparing_at
 * @property Carbon|null $ready_at
 * @property Carbon|null $submitted_at
 * @property Carbon|null $archived_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $committed_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $voided_at
 * @property string|null $original_total
 * @property string|null $store_session_id
 * @property int $version
 * @property OrderSource $source
 * @property OrderType $order_type
 * @property CommercialStatus $commercial_status
 * @property PaymentStatus $payment_status
 * @property PaymentTerm|null $payment_term
 * @property KitchenStatus $kitchen_status
 * @property KitchenTicket|null $kitchenTicket
 */
#[Fillable(['branch_id', 'store_session_id', 'source', 'order_type', 'customer_label', 'branch_table_id', 'commercial_status', 'payment_status', 'payment_term', 'kitchen_status', 'subtotal', 'total', 'original_total', 'created_by_user_id', 'loaded_by_user_id', 'submitted_at', 'archived_at', 'archive_reason', 'committed_at', 'completed_at', 'preparing_at', 'ready_at', 'voided_at', 'edited_at', 'pay_later_idempotency_key', 'version', 'customer_qr_session_id', 'public_tracking_id', 'qr_idempotency_key', 'qr_intent_hash', 'table_name_snapshot'])]
class Order extends Model
{
    /** @use HasFactory<OrderFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::updating(function (Order $order): void {
            if ($order->isDirty('qr_sequence')) {
                throw new \LogicException('QR sequence is immutable.');
            }
            if ($order->isDirty(['order_number', 'reference_number']) && ! (
                $order->getRawOriginal('source') === OrderSource::CustomerQr->value
                && $order->getRawOriginal('commercial_status') === CommercialStatus::Submitted->value
                && $order->getRawOriginal('committed_at') === null
                && $order->getRawOriginal('order_number') === null
                && $order->getRawOriginal('reference_number') === null
                && $order->order_number !== null && $order->reference_number !== null
                && $order->committed_at !== null
            )) {
                throw new \LogicException('Order identifiers are immutable.');
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'source' => OrderSource::class,
            'order_type' => OrderType::class,
            'commercial_status' => CommercialStatus::class,
            'payment_status' => PaymentStatus::class,
            'payment_term' => PaymentTerm::class,
            'kitchen_status' => KitchenStatus::class,
            'subtotal' => 'decimal:2',
            'total' => 'decimal:2',
            'original_total' => 'decimal:2',
            'version' => 'integer',
            'qr_sequence' => 'integer',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'submitted_at' => 'datetime',
            'archived_at' => 'datetime',
            'committed_at' => 'datetime',
            'completed_at' => 'datetime',
            'voided_at' => 'datetime',
            'edited_at' => 'datetime',
        ];
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

    /** @return BelongsTo<BranchTable, $this> */
    public function branchTable(): BelongsTo
    {
        return $this->belongsTo(BranchTable::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return HasMany<OrderItem, $this> */
    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    /** @return HasMany<Payment, $this> */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /** @return HasMany<OrderAdjustment, $this> */
    public function adjustments(): HasMany
    {
        return $this->hasMany(OrderAdjustment::class);
    }

    /** @return HasMany<InventoryMovement, $this> */
    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(InventoryMovement::class);
    }

    /** @return HasOne<KitchenTicket, $this> */
    public function kitchenTicket(): HasOne
    {
        return $this->hasOne(KitchenTicket::class);
    }

    /** @return HasOne<OrderVoid, $this> */
    public function voidRecord(): HasOne
    {
        return $this->hasOne(OrderVoid::class);
    }

    /** @return HasOne<OrderPickupToken, $this> */
    public function pickupToken(): HasOne
    {
        return $this->hasOne(OrderPickupToken::class);
    }
}
