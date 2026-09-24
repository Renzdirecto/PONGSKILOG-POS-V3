<?php

namespace App\Http\Controllers;

use App\Actions\StoreSessions\RecordStoreSessionGiveaway;
use App\Actions\StoreSessions\ReverseStoreSessionGiveaway;
use App\Enums\BranchStatus;
use App\Http\Requests\StoreSessionGiveawayRequest;
use App\Models\Branch;
use App\Models\StoreSessionGiveaway;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\BranchCatalog;
use App\Support\CurrentStoreSessionExpenses;
use App\Support\PosAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StoreSessionGiveawayController extends Controller
{
    /**
     * The canonical Branch catalog (Groups, availability and Recipe state) that the Giveaway product picker customizes.
     */
    public function catalog(Request $request, ActiveBranchContext $context, PosAccess $access, BranchCatalog $catalog): JsonResponse
    {
        $branch = $this->branch($request, $context, $access);
        abort_unless($branch->status === BranchStatus::Active, 404);

        return response()->json($catalog->browse($branch, customization: true))->header('Cache-Control', 'no-store');
    }

    /**
     * Record a free item given away during the current Store Session.
     */
    public function store(StoreSessionGiveawayRequest $request, ActiveBranchContext $context, PosAccess $access, RecordStoreSessionGiveaway $record, CurrentStoreSessionExpenses $present): JsonResponse
    {
        $giveaway = $record->execute($this->user($request), $this->branch($request, $context, $access), $request->all());

        return $this->respond($giveaway, $present);
    }

    /**
     * Reverse a mistaken Giveaway once, restoring exactly its recorded stock.
     */
    public function reverse(Request $request, StoreSessionGiveaway $giveaway, ActiveBranchContext $context, PosAccess $access, ReverseStoreSessionGiveaway $reverse, CurrentStoreSessionExpenses $present): JsonResponse
    {
        $branch = $this->branch($request, $context, $access);
        abort_unless($giveaway->branch_id === $branch->id, 404);
        $reverse->execute($this->user($request), $branch, $giveaway, $request->only(['idempotency_key', 'reason']));

        return $this->respond($giveaway, $present);
    }

    private function user(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** The server-chosen active Branch; a browser-supplied Branch id is never trusted. */
    private function branch(Request $request, ActiveBranchContext $context, PosAccess $access): Branch
    {
        $user = $this->user($request);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        abort_unless($user->hasPermission('store_expenses.manage'), 403);
        $access->authorize($user, $branch);

        return $branch;
    }

    private function respond(StoreSessionGiveaway $giveaway, CurrentStoreSessionExpenses $present): JsonResponse
    {
        $giveaway = StoreSessionGiveaway::query()->whereKey($giveaway->id)->with(CurrentStoreSessionExpenses::giveawayRelations())->sole();

        return response()->json(['giveaway' => $present->giveaway($giveaway)])->header('Cache-Control', 'no-store');
    }
}
