<?php

namespace App\Models;

use App\Support\StaffRoles;
use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Carbon;

/**
 * A System Role (the five canonical roles, identified by their unchanged machine `name`) or a Custom Role created by a
 * Super Admin (`name` = stable `custom_{id}` key, `label` = editable display name). Permissions say WHAT a role may do;
 * `scope` says WHERE: a Branch role works only at its active Branch assignments, a business-wide role reaches every
 * Branch without assignments. System role scope always follows the canonical name, never editable metadata.
 *
 * @property int $id
 * @property string $name
 * @property string|null $label
 * @property bool $is_system
 * @property 'branch'|'business'|null $scope
 * @property Carbon|null $archived_at
 */
#[Fillable(['name'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory;

    public const SCOPE_BRANCH = 'branch';

    public const SCOPE_BUSINESS = 'business';

    /** Custom Role machine keys: `custom_` plus the Role id, never derived from the editable display name. */
    public const CUSTOM_PREFIX = 'custom_';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'is_system' => 'boolean',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * A canonical role name always carries its system metadata, however the row is created (seeder, factory, tests).
     */
    protected static function booted(): void
    {
        static::creating(function (Role $role): void {
            if (StaffRoles::isSystem($role->name)) {
                $role->forceFill([
                    'label' => StaffRoles::LABELS[$role->name],
                    'is_system' => true,
                    'scope' => StaffRoles::SYSTEM_SCOPES[$role->name],
                    'archived_at' => null,
                ]);
            }
            $role->label ??= $role->name;
        });
    }

    /** @return BelongsToMany<User, $this> */
    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_roles');
    }

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class, 'role_permissions');
    }

    public function displayLabel(): string
    {
        return StaffRoles::LABELS[$this->name] ?? ($this->label ?? $this->name);
    }

    public function isCustom(): bool
    {
        return ! StaffRoles::isSystem($this->name);
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The role's access scope: canonical for system roles, the stored scope for a Custom Role (null = not assignable).
     *
     * @return 'branch'|'business'|null
     */
    public function accessScope(): ?string
    {
        return StaffRoles::SYSTEM_SCOPES[$this->name] ?? $this->scope;
    }

    public function isBusinessWide(): bool
    {
        return $this->accessScope() === self::SCOPE_BUSINESS;
    }

    /**
     * Whether a Staff account may be given this role now: every System role, and an active Custom Role with a scope.
     */
    public function isAssignable(): bool
    {
        return ! $this->isCustom() || (! $this->isArchived() && $this->scope !== null);
    }

    /**
     * Roles that give business-wide scope: Owner and Super Admin by canonical name, plus active business-wide Custom
     * Roles by metadata. A System role's stored metadata never widens it.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeBusinessWide(Builder $query): void
    {
        $query->where(fn (Builder $roles) => $roles
            ->whereIn('roles.name', StaffRoles::BUSINESS_WIDE)
            ->orWhere(fn (Builder $custom) => $this->customWithScope($custom, self::SCOPE_BUSINESS)));
    }

    /**
     * Roles that run Cashier operations at a Branch (POS, Store Session, Transaction History): Cashier, Cashier +
     * Kitchen, Super Admin and active Branch Custom Roles. The permission itself (for example `pos.access`) is still
     * required separately; this only says the role's scope supports Cashier operations.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeCashierOperations(Builder $query): void
    {
        $query->where(fn (Builder $roles) => $roles
            ->whereIn('roles.name', StaffRoles::CASHIER_OPERATIONS)
            ->orWhere(fn (Builder $custom) => $this->customWithScope($custom, self::SCOPE_BRANCH)));
    }

    /**
     * Active Custom Roles a Staff account may be assigned to, in display order.
     *
     * @param  Builder<Role>  $query
     */
    public function scopeAssignableCustom(Builder $query): void
    {
        $query->whereNotIn('roles.name', StaffRoles::names())
            ->where('roles.is_system', false)
            ->whereNull('roles.archived_at')
            ->whereIn('roles.scope', [self::SCOPE_BRANCH, self::SCOPE_BUSINESS])
            ->orderByRaw('LOWER(roles.label)')
            ->orderBy('roles.id');
    }

    /** @param  Builder<Role>  $query */
    private function customWithScope(Builder $query, string $scope): void
    {
        $query->whereNotIn('roles.name', StaffRoles::names())
            ->where('roles.is_system', false)
            ->whereNull('roles.archived_at')
            ->where('roles.scope', $scope);
    }
}
