<?php

namespace App\Http\Controllers;

use App\Http\Requests\OperationsPageRequest;
use App\Models\Branch;
use App\Models\OperationPlan;
use App\Support\OperationsAccess;
use App\Support\OperationsWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Owner Operations pages. Each is a real route with the active Plan in the URL (?plan=), inside the existing
 * Owner/Super Admin management shell and global Branch context. All pages are read-only; writes go through the
 * dedicated Operations controllers.
 */
class OperationsController extends Controller
{
    public function __construct(private OperationsAccess $access, private OperationsWorkspace $workspace) {}

    public function plans(OperationsPageRequest $request): Response
    {
        [$branch, $plans, $active] = $this->scope($request);

        return Inertia::render('operations/plans', [
            'operations' => $this->workspace->context('plans', $branch, $plans, $active),
            ...$this->workspace->plansPage($branch, $plans),
        ]);
    }

    public function overview(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/overview', [
            'operations' => $this->workspace->context('overview', $branch, $plans, $active),
            ...$this->workspace->overviewPage($branch, $active),
        ]);
    }

    public function ingredients(OperationsPageRequest $request): Response
    {
        [$branch, $plans, $active] = $this->scope($request);

        return Inertia::render('operations/ingredients', [
            'operations' => $this->workspace->context('ingredients', $branch, $plans, $active),
            ...$this->workspace->ingredientsPage($branch),
        ]);
    }

    public function recipes(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/recipes', [
            'operations' => $this->workspace->context('recipes', $branch, $plans, $active),
            'selectedProductId' => $request->validated('product'),
            ...$this->workspace->recipesPage($branch, $active),
        ]);
    }

    public function stock(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/stock', [
            'operations' => $this->workspace->context('stock', $branch, $plans, $active),
            ...($branch === null ? ['ingredients' => [], 'movements' => []] : $this->workspace->stockPage($branch, $active)),
        ]);
    }

    public function pamamalengke(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/pamamalengke', [
            'operations' => $this->workspace->context('pamamalengke', $branch, $plans, $active),
            'mode' => $request->validated('mode') ?? 'plan',
            ...$this->workspace->pamamalengkePage($branch, $active),
        ]);
    }

    public function purchases(OperationsPageRequest $request): Response
    {
        [$branch, $plans, $active] = $this->scope($request);
        $scope = $request->validated('scope') === 'all' || $active === null ? 'all' : 'plan';

        return Inertia::render('operations/purchases', [
            'operations' => $this->workspace->context('purchases', $branch, $plans, $active),
            'scope' => $scope,
            ...$this->workspace->purchasesPage($branch, $scope === 'all' ? null : $active, (int) ($request->validated('page') ?? 1)),
        ]);
    }

    /** @return array{0: Branch|null, 1: Collection<int, OperationPlan>, 2: OperationPlan|null} */
    private function scope(OperationsPageRequest $request): array
    {
        $user = $this->access->authorize($request->user());
        $plans = $this->workspace->activePlans();

        return [$this->access->branch($user), $plans, $this->workspace->resolvePlan($plans, $request->validated('plan'))];
    }
}
