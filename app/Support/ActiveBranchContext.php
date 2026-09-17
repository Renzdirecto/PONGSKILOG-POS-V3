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

    public function current(User $user): ?Branch
    {
        $branchId = $this->session->get(self::SESSION_KEY);

        if ($branchId !== null) {
            if (! is_string($branchId)) {
                $this->clear();

                return null;
            }

            $branch = Branch::query()->find($branchId);

            if ($branch === null || Gate::forUser($user)->denies('select', $branch)) {
                $this->clear();

                return null;
            }

            return $branch;
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
