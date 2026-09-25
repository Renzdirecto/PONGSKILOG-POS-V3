<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Operations access (`operations.manage`, independent of Product inventory `inventory.manage`; Operations never mutates
 * Product stock). Cashier and Kitchen roles never manage Ingredients, recipes, Plans or pamamalengke (their POS sales
 * still consume Ingredients as domain behavior).
 *
 * WHAT belongs to whom:
 * - Shared definitions (Ingredient identity/unit/targets, Recipes, Add-on effects, recipe mode, Pamalengke Plans) are
 *   one business-wide set used by every Branch, so only a business-wide account changes them (authorizeDefinitions()).
 * - Branch execution data (Ingredient stock and movements, the Pamamalengke working list, Pamamalengke purchases) is
 *   physical and per Branch. A business-wide account works on the selected Branch (All Branches is read-only); a
 *   Branch-scoped account (Branch Custom Role) only on its selected assigned Branch, never All Branches.
 * Physical stock mutations always need one concrete Branch from the global Branch context, never a browser-supplied id.
 */
class OperationsAccess
{
    public function __construct(private ActiveBranchContext $context) {}

    public function allows(?User $user): bool
    {
        $user = $user?->exists ? User::query()->whereKey($user->getKey())->first() : null;

        return $user !== null && $user->is_active
            && $user->hasPermission('operations.manage')
            && ($user->hasBusinessWideScope() || $user->branches()->wherePivot('is_active', true)->exists());
    }

    public function authorize(?User $user): User
    {
        if (! $this->allows($user)) {
            throw new AuthorizationException('Operations access is required to manage Operations.');
        }

        return User::query()->whereKey($user?->getKey())->firstOrFail();
    }

    /**
     * Shared Operations definitions change every Branch at once, so they need business-wide Operations access. A
     * Branch-scoped Operations role reads and uses them but never edits them.
     */
    public function authorizeDefinitions(?User $user): User
    {
        $user = $this->authorize($user);
        if (! $user->hasBusinessWideScope()) {
            throw new AuthorizationException('Ingredients, Recipes and Plans are shared by every Branch. Only a business-wide Operations role can change them.');
        }

        return $user;
    }

    public function canManageDefinitions(User $user): bool
    {
        return $this->allows($user) && $user->hasBusinessWideScope();
    }

    /**
     * The selected Branch, null for All Branches (business-wide read-only analytics), or false when a Branch-scoped
     * account has no selected assigned Branch yet (it is sent to choose one, never shown All Branches).
     */
    public function branch(User $user): Branch|false|null
    {
        return $this->context->managementBranch($user);
    }

    /** One concrete, active Branch for a physical stock or purchase mutation. */
    public function mutableBranch(User $user): Branch
    {
        $branch = $this->context->current($user);
        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => 'Choose one Branch from the header first. Ingredient stock is physical and branch-specific, so it cannot change for All Branches.',
            ]);
        }
        if ($branch->status !== BranchStatus::Active) {
            throw ValidationException::withMessages(['branch' => $branch->name.' is not active. Ingredient stock can change only for an active Branch.']);
        }

        return Branch::query()->whereKey($branch->id)->firstOrFail();
    }
}
