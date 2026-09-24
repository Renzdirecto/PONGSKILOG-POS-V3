<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ActiveBranchController extends Controller
{
    public function update(
        Request $request,
        Branch $branch,
        ActiveBranchContext $activeBranchContext,
    ): RedirectResponse {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $activeBranchContext->set($user, $branch);

        /** An optional same-application path (e.g. a Branch's Product settings); anything else lands on the workspace. */
        $target = $request->input('redirect');
        if (is_string($target) && strlen($target) <= 2000 && preg_match('#\A/(?![/\\\\])[^\s\\\\]*\z#', $target) === 1) {
            return redirect($target);
        }

        return to_route('workspace');
    }

    public function destroy(Request $request, ActiveBranchContext $activeBranchContext): RedirectResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);
        abort_unless($user->hasBusinessWideScope(), 403);

        $activeBranchContext->clear();

        return to_route('workspace');
    }
}
