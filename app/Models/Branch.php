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
#[Fillable(['code', 'name', 'status', 'address', 'contact', 'operating_hours', 'qr_ordering_enabled', 'facebook_url', 'website_url', 'receipt_name', 'receipt_address', 'receipt_contact', 'receipt_footer', 'receipt_show_logo', 'receipt_logo_path'])]
class Branch extends Model
{
    protected $attributes = ['qr_ordering_enabled' => true, 'receipt_show_logo' => true];

    /** @use HasFactory<BranchFactory> */
    use HasFactory, HasUuids;

    protected static function booted(): void
    {
        static::creating(function (Branch $branch): void {
            $branch->kiosk_code = $branch->code;
        });
        static::updating(function (Branch $branch): void {
            if ($branch->isDirty('kiosk_code')) {
                throw new \LogicException('The public kiosk address is immutable.');
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => BranchStatus::class,
            'qr_ordering_enabled' => 'boolean',
            'receipt_show_logo' => 'boolean',
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
