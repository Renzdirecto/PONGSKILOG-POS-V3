<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property bool $is_active
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_active' => 'boolean',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsToMany<Role, $this> */
    public function roles(): BelongsToMany
    {
        return $this->belongsToMany(Role::class, 'user_roles');
    }

    public function hasRole(string $role): bool
    {
        return $this->roles()
            ->where('roles.name', $role)
            ->exists();
    }

    public function hasPermission(string $permission): bool
    {
        return $this->roles()
            ->whereHas('permissions', function (Builder $query) use ($permission): void {
                $query->where('permissions.name', $permission);
            })
            ->exists();
    }

    public function hasBusinessWideScope(): bool
    {
        return $this->roles()
            ->whereIn('roles.name', ['super_admin', 'owner'])
            ->exists();
    }

    /**
     * Cashier operational surfaces belong to assigned Cashiers and to Super Admin, whose full-access role
     * covers every operational workspace. Owner business-wide scope alone never grants Cashier operations.
     */
    public function hasCashierOperationsRole(): bool
    {
        return $this->roles()
            ->whereIn('roles.name', ['cashier', 'cashier_kitchen', 'super_admin'])
            ->exists();
    }

    /**
     * Operational Branch access requires an active assignment, except for business-wide Super Admin,
     * which is never given fabricated Branch assignments.
     */
    public function hasOperationalBranchAccess(Branch $branch): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->where(function (Builder $query) use ($branch): void {
                $query->whereHas('roles', function (Builder $roles): void {
                    $roles->where('roles.name', 'super_admin');
                })->orWhereHas('branches', function (Builder $branches) use ($branch): void {
                    $branches->whereKey($branch->getKey())
                        ->where('user_branch_assignments.is_active', true);
                });
            })
            ->exists();
    }

    public function canAccessBranch(Branch $branch): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->hasBusinessWideScope()) {
            return true;
        }

        return $this->branches()
            ->whereKey($branch->getKey())
            ->wherePivot('is_active', true)
            ->exists();
    }

    /** @return HasMany<StoreSession, $this> */
    public function openedStoreSessions(): HasMany
    {
        return $this->hasMany(StoreSession::class, 'opened_by_user_id');
    }

    /** @return HasMany<StoreSession, $this> */
    public function closedStoreSessions(): HasMany
    {
        return $this->hasMany(StoreSession::class, 'closed_by_user_id');
    }

    /** @return HasMany<StoreSessionExpense, $this> */
    public function createdStoreSessionExpenses(): HasMany
    {
        return $this->hasMany(StoreSessionExpense::class, 'created_by_user_id');
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'user_branch_assignments')
            ->withPivot('is_active');
    }
}
