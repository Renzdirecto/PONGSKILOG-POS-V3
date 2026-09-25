<?php

namespace App\Http\Requests;

use App\Models\User;

/**
 * The shared Transaction History in the management shell: a business-wide user reads All Branches or the selected
 * Branch, a Branch-scoped account only its selected assigned Branch (the controller enforces the scope). It validates
 * the same filters as the Cashier history; this read never grants Cashier writes.
 */
class BusinessTransactionHistoryRequest extends TransactionHistoryRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof User
            && $user->is_active
            && $user->hasPermission('transactions.view');
    }
}
