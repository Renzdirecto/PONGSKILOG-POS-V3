<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Operations access (`operations.manage`, independent of Product inventory `inventory.manage`; Operations never mutates
 * Product stock). Cashier and Kitchen roles never manage Ingredients, recipes, Plans or pamamalengke (their POS sales
 * still consume Ingredients as domain behavior).
 *
 * Every Operations record belongs to one Branch: Plans, Ingredients (unit, targets, purchase unit, cost, rule), Recipes,
 * Add-on effects, the recipe mode (on the Branch Product), Ingredient stock and movements, the Pamamalengke working list
 * and purchases. Reads and writes always run on one concrete Branch from the global Branch context (never a
 * browser-supplied id): a business-wide account on the Branch it selected, a Branch-scoped account only on its selected
 * assigned Branch. All Branches has no single setup, so it is read-only (purchases list only). A record of another
 * Branch is reported as not found, so ids of other Branches cannot be probed or changed.
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
     * The selected Branch, null for All Branches (business-wide read-only), or false when a Branch-scoped account has no
     * selected assigned Branch yet (it is sent to choose one, never shown All Branches).
     */
    public function branch(User $user): Branch|false|null
    {
        return $this->context->managementBranch($user);
    }

    /**
     * The one Branch whose Operations setup (Plans, Ingredients, Recipes, Add-on effects, recipe mode) this request
     * configures. Configuration needs no open Store, but it always needs one concrete selected Branch.
     */
    public function configurationBranch(User $user): Branch
    {
        $branch = $this->context->current($user);
        if ($branch === null) {
            throw ValidationException::withMessages([
                'branch' => 'Choose one Branch from the header first. Plans, Ingredients and Recipes belong to one Branch, so they cannot change for All Branches.',
            ]);
        }

        return Branch::query()->whereKey($branch->id)->firstOrFail();
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

    /** Rejects a Branch-owned record (Plan, Ingredient, list entry) of any other Branch as not found. */
    public function ownedBy(Model $record, Branch $branch): void
    {
        abort_unless((string) $record->getAttribute('branch_id') === (string) $branch->id, 404);
    }
}
