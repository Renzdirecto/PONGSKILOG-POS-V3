<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\Gate;

class ActiveBranchContext
{
    public const SESSION_KEY = 'active_branch_id';

    public function __construct(private Session $session) {}

    /**
     * The selected Branch, re-authorized on every call. A stale or forged selection is dropped and the account continues
     * as if it had none selected (a single assigned Branch is chosen again), so the answer never depends on how many
     * times it was asked within one request.
     */
    public function current(User $user): ?Branch
    {
        $branchId = $this->session->get(self::SESSION_KEY);

        if ($branchId !== null) {
            $branch = is_string($branchId) ? Branch::query()->find($branchId) : null;

            if ($branch !== null && Gate::forUser($user)->allows('select', $branch)) {
                return $branch;
            }

            $this->clear();
        }

        if (! $user->is_active || $user->hasBusinessWideScope()) {
            return null;
        }

        $assignedBranches = $user->branches()
            ->wherePivot('is_active', true)
            ->limit(2)
            ->get();

        if ($assignedBranches->count() !== 1) {
            return null;
        }

        $branch = $assignedBranches->sole();
        $this->session->put(self::SESSION_KEY, $branch->getKey());

        return $branch;
    }

    /**
     * The Branch a management page (Dashboard, Transactions, Reports, Products, Inventory, Operations, Staff, Settings)
     * works on. Business-wide accounts get the selected Branch or null (All Branches). A Branch-scoped account (Branch
     * Custom Role or Branch staff) only ever works on its selected assigned Branch; false means it has none selected yet,
     * so the caller sends it to the workspace (Branch picker) instead of ever showing All Branches.
     */
    public function managementBranch(User $user): Branch|false|null
    {
        $branch = $this->current($user);

        return $branch === null && ! $user->hasBusinessWideScope() ? false : $branch;
    }

    public function set(User $user, Branch $branch): void
    {
        Gate::forUser($user)->authorize('select', $branch);

        $this->session->put(self::SESSION_KEY, $branch->getKey());
    }

    public function clear(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }
}
