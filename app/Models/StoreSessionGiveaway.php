<?php

namespace App\Models;

use App\Enums\GiveawayReason;
use App\Enums\GiveawayStockMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A Product given away free in a Store Session: ₱0 revenue, no Payment, no Store Expense. Historical and immutable;
 * a mistake is corrected by one compensating StoreSessionGiveawayReversal.
 *
 * @phpstan-type Selection array{group_id: string, group_name: string, semantic_role: string|null, option_id: string, option_name: string}
 * @phpstan-type BasisLine array{ingredient_id: string, name: string, unit: string, quantity: string}
 * @phpstan-type StockBasis array{size_key: string, base: list<BasisLine>, add_ons: list<array{option_id: string, option_name: string, lines: list<BasisLine>}>}
 *
 * @property GiveawayReason $reason_code
 * @property GiveawayStockMode $stock_mode
 * @property int $quantity
 * @property list<Selection> $selections
 * @property StockBasis|null $stock_basis
 */
#[Fillable([
    'branch_id', 'store_session_id', 'product_id', 'product_name_snapshot', 'size_key', 'size_name_snapshot',
    'selections', 'quantity', 'stock_mode', 'stock_basis', 'inventory_movement_id', 'reason_code', 'note',
    'created_by_user_id', 'idempotency_key', 'intent_hash',
])]
class StoreSessionGiveaway extends Model
{
    use HasUuids;

    protected static function booted(): void
    {
        static::updating(fn (): never => throw new \LogicException('Giveaways are historical records; reverse one instead.'));
        static::deleting(fn (): never => throw new \LogicException('Giveaways are historical records; reverse one instead.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'reason_code' => GiveawayReason::class,
            'stock_mode' => GiveawayStockMode::class,
            'quantity' => 'integer',
            'selections' => 'array',
            'stock_basis' => 'array',
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

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** @return BelongsTo<InventoryMovement, $this> */
    public function inventoryMovement(): BelongsTo
    {
        return $this->belongsTo(InventoryMovement::class);
    }

    /** @return HasMany<IngredientMovement, $this> */
    public function ingredientMovements(): HasMany
    {
        return $this->hasMany(IngredientMovement::class, 'store_session_giveaway_id');
    }

    /** @return HasOne<StoreSessionGiveawayReversal, $this> */
    public function reversal(): HasOne
    {
        return $this->hasOne(StoreSessionGiveawayReversal::class, 'giveaway_id');
    }
}
