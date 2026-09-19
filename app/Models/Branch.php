<?php

namespace App\Models;

use App\Enums\BranchStatus;
use Database\Factories\BranchFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property BranchStatus $status
 */
#[Fillable(['code', 'name', 'status', 'address', 'contact', 'operating_hours'])]
class Branch extends Model
{
    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasUuids;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'operating_hours' => 'array',
        ];
    }

    /** @return HasMany<BranchTable, $this> */
    public function tables(): HasMany
    {
        return $this->hasMany(BranchTable::class);
    }

    /** @return HasMany<Order, $this> */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** @return HasMany<BranchInventory, $this> */
    public function inventoryBalances(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    /** @return HasMany<BranchProduct, $this> */
    public function branchProducts(): HasMany
    {
        return $this->hasMany(BranchProduct::class);
    }

    /** @return HasMany<StoreSession, $this> */
    public function storeSessions(): HasMany
    {
        return $this->hasMany(StoreSession::class);
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_branch_assignments')
            ->withPivot('is_active');
    }
}
