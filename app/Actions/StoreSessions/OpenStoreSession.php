<?php

namespace App\Actions\StoreSessions;

use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

class OpenStoreSession
{
    public function execute(
        User $user,
        Branch $branch,
        string $openingCashAmount,
        string $openingCashlessAmount,
    ): StoreSession {
        return DB::transaction(function () use ($user, $branch, $openingCashAmount, $openingCashlessAmount): StoreSession {
            $branch = Branch::query()->whereKey($branch->getKey())->lockForUpdate()->firstOrFail();
            $user = $user->exists ? User::query()->whereKey($user->getKey())->first() : null;

            if ($user === null || ! $user->is_active || ! $user->hasPermission('store.open_close')) {
                throw new AuthorizationException('You are not authorized to open this store.');
            }

            Gate::forUser($user)->authorize('select', $branch);

            /** Frozen security rules require a Cashier role and assignment, even for business-wide users. */
            if ((! $user->hasRole('cashier') && ! $user->hasRole('cashier_kitchen'))
                || ! $user->branches()->whereKey($branch->getKey())->wherePivot('is_active', true)->exists()) {
                throw new AuthorizationException('Only an assigned cashier may open this store.');
            }

            if ($branch->status !== BranchStatus::Active) {
                throw new AuthorizationException('Only an active branch may be opened.');
            }

            /** Unsigned fixed-point strings fit numeric(14,2) without float conversion or rounding. */
            $moneyRules = ['required', 'regex:/\A[0-9]{1,12}(?:\.[0-9]{1,2})?\z/'];

            Validator::make([
                'opening_cash_amount' => $openingCashAmount,
                'opening_cashless_amount' => $openingCashlessAmount,
            ], [
                'opening_cash_amount' => $moneyRules,
                'opening_cashless_amount' => $moneyRules,
            ], [
                'regex' => 'The :attribute must be a non-negative decimal with up to 12 integer digits and 2 decimal places.',
            ])->validate();

            $openSessions = $branch->storeSessions()->where('status', StoreSessionStatus::Open);

            if ($session = $openSessions->first()) {
                return $session;
            }

            try {
                /** A savepoint permits recovery without querying an aborted PostgreSQL transaction. */
                return DB::transaction(fn (): StoreSession => $branch->storeSessions()->create([
                    'status' => StoreSessionStatus::Open,
                    'opened_by_user_id' => $user->id,
                    'opened_at' => now(),
                    'opening_cash_amount' => $openingCashAmount,
                    'opening_cashless_amount' => $openingCashlessAmount,
                ]));
            } catch (UniqueConstraintViolationException $exception) {
                if (($exception->errorInfo[0] ?? null) !== '23505'
                    || ! str_contains($exception->errorInfo[2] ?? '', '"store_sessions_one_open_per_branch_unique"')) {
                    throw $exception;
                }

                return $openSessions->first() ?? throw $exception;
            }
        });
    }
}
