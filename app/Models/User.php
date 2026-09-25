<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Support\EffectivePermissions;
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
 * @property string|null $employee_id
 * @property string|null $avatar_path
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

    /**
     * The account's effective permission: Role baseline plus explicit per-user overrides (Super Admin is locked full).
     */
    public function hasPermission(string $permission): bool
    {
        return EffectivePermissions::has($this, $permission);
    }

    /** @return HasMany<UserPermissionOverride, $this> */
    public function permissionOverrides(): HasMany
    {
        return $this->hasMany(UserPermissionOverride::class);
    }

    /**
     * Business-wide scope comes from role semantics (Owner, Super Admin, or an active business-wide Custom Role), never
     * from a Branch assignment or a page permission. See Role::scopeBusinessWide().
     */
    public function hasBusinessWideScope(): bool
    {
        return $this->roles()->businessWide()->exists();
    }

    /**
     * Cashier operational surfaces belong to assigned Cashiers, Custom Roles (Branch or business-wide) and Super Admin,
     * whose full-access role covers every operational workspace. Owner scope never grants Cashier operations. See
     * Role::scopeCashierOperations().
     */
    public function hasCashierOperationsRole(): bool
    {
        return $this->roles()->cashierOperations()->exists();
    }

    /**
     * Whether the account operates at any selected Branch without assignments (Super Admin and business-wide Custom
     * Roles). See Role::scopeOperatesEveryBranch().
     */
    public function operatesEveryBranch(): bool
    {
        return $this->roles()->operatesEveryBranch()->exists();
    }

    /**
     * Operational Branch access requires an active assignment, except for Super Admin and business-wide Custom Roles,
     * which are never given fabricated Branch assignments and operate at the one Branch they select.
     */
    public function hasOperationalBranchAccess(Branch $branch): bool
    {
        return self::query()
            ->whereKey($this->getKey())
            ->where(function (Builder $query) use ($branch): void {
                $query->whereHas('roles', function (Builder $roles): void {
                    $roles->whereIn('roles.id', Role::query()->operatesEveryBranch()->select('roles.id'));
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
