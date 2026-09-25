<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Owner Operations access. Management belongs to active business-wide users (Owner, Super Admin, business-wide Custom
 * Roles) holding operations.manage, which is independent of Product inventory (inventory.manage); Operations never
 * mutates Product stock. Cashier and Kitchen roles never manage Ingredients, recipes, Plans or pamamalengke (their POS
 * sales still consume Ingredients as domain behavior). Physical stock mutations always need one concrete Branch from the
 * global Branch context, never an ambiguous All Branches scope or a browser-supplied branch id.
 */
class OperationsAccess
{
    public function __construct(private ActiveBranchContext $context) {}

    public function allows(?User $user): bool
    {
        $user = $user?->exists ? User::query()->whereKey($user->getKey())->first() : null;

        return $user !== null && $user->is_active
            && $user->hasPermission('operations.manage')
            && $user->hasBusinessWideScope();
    }

    public function authorize(?User $user): User
    {
        if (! $this->allows($user)) {
            throw new AuthorizationException('Operations access is required to manage Operations.');
        }

        return User::query()->whereKey($user?->getKey())->firstOrFail();
    }

    /** The selected Branch, or null for All Branches (read-only aggregated analytics). */
    public function branch(User $user): ?Branch
    {
        return $this->context->current($user);
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
