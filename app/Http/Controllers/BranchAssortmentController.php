<?php

namespace App\Http\Controllers;

use App\Actions\Catalog\ConfigureBranchAssortment;
use App\Models\Branch;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Bulk Branch assortment tools of the Products page. The destination is always the selected Branch from the global
 * Branch context (never a browser-supplied id); the copy source is validated by ConfigureBranchAssortment.
 */
class BranchAssortmentController extends Controller
{
    /** Add existing canonical Products to the selected Branch's assortment. */
    public function store(Request $request, ActiveBranchContext $context, ConfigureBranchAssortment $assortment): RedirectResponse
    {
        $request->validate(['product_ids' => ['required', 'array', 'min:1', 'max:'.ConfigureBranchAssortment::MAX_PRODUCTS]]);
        $user = $this->actor($request);
        $result = $assortment->add($user, $this->destination($user, $context), (array) $request->input('product_ids'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['added'] === 0
            ? 'Those products are already in this Branch.'
            : $result['added'].' '.($result['added'] === 1 ? 'product' : 'products').' added to this Branch.']);

        return back();
    }

    /**
     * Remove Products from the selected Branch's assortment (not the same as unavailable). The global Product, its
     * stock history and past Orders are kept.
     */
    public function destroy(Request $request, ActiveBranchContext $context, ConfigureBranchAssortment $assortment): RedirectResponse
    {
        $request->validate(['product_ids' => ['required', 'array', 'min:1', 'max:'.ConfigureBranchAssortment::MAX_PRODUCTS]]);
        $user = $this->actor($request);
        $branch = $this->destination($user, $context);
        $result = $assortment->remove($user, $branch, (array) $request->input('product_ids'));

        Inertia::flash('toast', ['type' => 'success', 'message' => $result['removed'] === 0
            ? 'Those products are not in '.$branch->code.'.'
            : $result['removed'].' '.($result['removed'] === 1 ? 'product' : 'products').' removed from '.$branch->code.'. Stock history and past orders are kept.']);

        return back();
    }

    /** Review rows for copying Product configuration from another authorized Branch into the selected Branch. */
    public function preview(Request $request, ActiveBranchContext $context, ConfigureBranchAssortment $assortment): JsonResponse
    {
        $data = $request->validate(['source_branch_id' => ['required', 'uuid']]);
        $user = $this->actor($request);
        $destination = $this->destination($user, $context);
        $source = $this->source($data['source_branch_id']);
        $preview = $assortment->preview($user, $source, $destination);

        return response()->json([
            'source' => $source->only(['id', 'name', 'code']),
            'destination' => $destination->only(['id', 'name', 'code']),
            'products' => $preview['products'],
            'operations' => $preview['operations'],
        ])->header('Cache-Control', 'no-store');
    }

    public function copy(Request $request, ActiveBranchContext $context, ConfigureBranchAssortment $assortment): RedirectResponse
    {
        $data = $request->validate([
            'source_branch_id' => ['required', 'uuid'],
            'product_ids' => ['required', 'array', 'min:1', 'max:'.ConfigureBranchAssortment::MAX_PRODUCTS],
            'overwrite' => ['required', 'boolean'],
            'copy_operations' => ['sometimes', 'boolean'],
        ]);
        $user = $this->actor($request);
        $result = $assortment->copy(
            $user,
            $this->source($data['source_branch_id']),
            $this->destination($user, $context),
            (array) $request->input('product_ids'),
            $request->boolean('overwrite'),
            $request->boolean('copy_operations'),
        );
        $changed = $result['copied'] + $result['overwritten'];
        $operations = $result['operations'];
        $conflicts = $result['conflicts'];

        Inertia::flash('toast', ['type' => $conflicts === [] ? 'success' : 'warning', 'message' => $changed.' '.($changed === 1 ? 'product' : 'products').' copied'
            .($result['skipped'] > 0 ? ' · '.$result['skipped'].' already in this Branch and kept' : '')
            .($conflicts === [] ? '' : ' · '.count($conflicts).' skipped for a conflict')
            .($operations === null ? '' : ' · Operations setup: '.$operations['recipes']['products'].' configured, '
                .($operations['ingredients']['new'] + $operations['ingredients']['replaced']).' ingredients, '
                .($operations['plans']['new'] + $operations['plans']['replaced']).' plans'
                .($operations['skipped'] === [] ? '' : ' ('.count($operations['skipped']).' skipped)'))
            .'. Stock was not copied.']);
        /** The copy dialog stays open on this result when anything was skipped, so each reason can be read. */
        Inertia::flash('assortmentCopy', [
            'copied' => $result['copied'],
            'overwritten' => $result['overwritten'],
            'kept' => $result['skipped'],
            'conflicts' => $conflicts,
            'operations_skipped' => $operations['skipped'] ?? [],
        ]);

        return back();
    }

    private function actor(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);

        return $user;
    }

    /** One concrete selected Branch; All Branches has no single assortment to change. */
    private function destination(User $user, ActiveBranchContext $context): Branch
    {
        $branch = $context->current($user);
        if ($branch === null) {
            throw ValidationException::withMessages(['branch' => 'Choose one Branch from the header first.']);
        }

        return $branch;
    }

    /** An unknown source is reported like an unauthorized one, so ids of other Branches cannot be probed. */
    private function source(string $id): Branch
    {
        $branch = Branch::query()->find($id);
        abort_if($branch === null, 403);

        return $branch;
    }
}
