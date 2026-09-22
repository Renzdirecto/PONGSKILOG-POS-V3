<?php

namespace App\Http\Controllers;

use App\Actions\StoreSessions\OpenStoreSession;
use App\Http\Requests\OpenStoreSessionRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\RedirectResponse;

class OpenStoreSessionController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        OpenStoreSessionRequest $request,
        ActiveBranchContext $activeBranchContext,
        OpenStoreSession $openStoreSession,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $branch = $activeBranchContext->current($user);

        if ($branch === null) {
            return to_route('workspace');
        }

        $openStoreSession->execute(
            $user,
            $branch,
            $request->string('opening_cash_amount')->toString(),
            $request->string('opening_cashless_amount')->toString(),
        );

        return to_route('workspaces.cashier');
    }
}
