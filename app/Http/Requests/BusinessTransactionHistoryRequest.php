<?php

namespace App\Http\Requests;

use App\Models\User;

/**
 * The shared Transaction History read by a business-wide user (Owner or Super Admin) across All Branches or the
 * selected Branch. It validates the same filters as the Cashier history; business scope never grants Cashier writes.
 */
class BusinessTransactionHistoryRequest extends TransactionHistoryRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('transactions.view')
            && $user->hasBusinessWideScope();
    }
}
