<?php

namespace App\Http\Controllers;

use App\Http\Requests\RecipeCapacityRequest;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\PosAccess;
use App\Support\RecipeCapacity;
use Illuminate\Http\JsonResponse;

/**
 * Server-authoritative Recipe capacity for the POS customization dialog: how many of the configured item the current
 * Branch Ingredient stock can still make after the rest of the cart, and what each Size or Add-on would allow.
 * Display only; every commit re-checks under the Ingredient locks.
 */
class PosRecipeCapacityController extends Controller
{
    public function __invoke(RecipeCapacityRequest $request, ActiveBranchContext $context, PosAccess $access, RecipeCapacity $capacity): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        $access->authorize($user, $branch);

        return response()->json($capacity->configuration($branch, $request->lines(), $request->focus()))
            ->header('Cache-Control', 'no-store');
    }
}
