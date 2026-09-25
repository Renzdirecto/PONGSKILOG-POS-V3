<?php

namespace App\Http\Controllers;

use App\Actions\Operations\CopyOperationsSetup;
use App\Http\Requests\OperationsPageRequest;
use App\Models\Branch;
use App\Models\OperationPlan;
use App\Models\User;
use App\Support\OperationsAccess;
use App\Support\OperationsWorkspace;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Operations pages. Each is a real route with the active Plan in the URL (?plan=), inside the management shell
 * and global Branch context (a Branch-scoped account only ever sees its selected assigned Branch). Every page shows the
 * selected Branch's own setup; All Branches has no single setup, so it offers only the Branch picker and the combined
 * purchase list. All pages are read-only; writes go through the dedicated Operations controllers.
 */
class OperationsController extends Controller
{
    private ?User $viewer = null;

    public function __construct(private OperationsAccess $access, private OperationsWorkspace $workspace) {}

    public function plans(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }

        return Inertia::render('operations/plans', [
            'operations' => $this->context('plans', $branch, $plans, $active),
            ...$this->workspace->plansPage($branch, $plans),
        ]);
    }

    public function overview(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }
        if ($branch === null || $active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/overview', [
            'operations' => $this->context('overview', $branch, $plans, $active),
            ...$this->workspace->overviewPage($branch, $active),
        ]);
    }

    public function ingredients(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }

        return Inertia::render('operations/ingredients', [
            'operations' => $this->context('ingredients', $branch, $plans, $active),
            ...$this->workspace->ingredientsPage($branch),
        ]);
    }

    public function recipes(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }
        if ($branch === null || $active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/recipes', [
            'operations' => $this->context('recipes', $branch, $plans, $active),
            'selectedProductId' => $request->validated('product'),
            ...$this->workspace->recipesPage($branch, $active),
        ]);
    }

    public function stock(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }
        if ($branch === null || $active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/stock', [
            'operations' => $this->context('stock', $branch, $plans, $active),
            ...$this->workspace->stockPage($branch, $active),
        ]);
    }

    public function pamamalengke(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }
        if ($branch === null || $active === null) {
            return to_route('operations.plans');
        }

        return Inertia::render('operations/pamamalengke', [
            'operations' => $this->context('pamamalengke', $branch, $plans, $active),
            'mode' => $request->validated('mode') ?? 'plan',
            ...$this->workspace->pamamalengkePage($branch, $active),
        ]);
    }

    public function purchases(OperationsPageRequest $request): Response|RedirectResponse
    {
        [$branch, $plans, $active] = $this->scope($request);
        if ($branch === false) {
            return to_route('workspace');
        }
        $scope = $request->validated('scope') === 'all' || $active === null ? 'all' : 'plan';

        return Inertia::render('operations/purchases', [
            'operations' => $this->context('purchases', $branch, $plans, $active),
            'scope' => $scope,
            ...$this->workspace->purchasesPage($branch, $scope === 'all' ? null : $active, (int) ($request->validated('page') ?? 1)),
        ]);
    }

    /**
     * The viewer's Branch scope (false = a Branch-scoped account without a selected assigned Branch), the active Plans
     * and the URL-selected Plan.
     *
     * @return array{0: Branch|false|null, 1: Collection<int, OperationPlan>, 2: OperationPlan|null}
     */
    private function scope(OperationsPageRequest $request): array
    {
        $user = $this->access->authorize($request->user());
        $this->viewer = $user;
        $branch = $this->access->branch($user);
        $plans = $this->workspace->activePlans($branch === false ? null : $branch);

        return [$branch, $plans, $this->workspace->resolvePlan($plans, $request->validated('plan'))];
    }

    /**
     * The Branch's Operations context plus whether the viewer may configure it (any Operations manager of that concrete
     * Branch; never for All Branches) and the Branches it may copy setup from.
     *
     * @param  Collection<int, OperationPlan>  $plans
     * @return array<string, mixed>
     */
    private function context(string $page, ?Branch $branch, Collection $plans, ?OperationPlan $active): array
    {
        return [
            ...$this->workspace->context($page, $branch, $plans, $active),
            'can_configure' => $branch !== null && $this->viewer !== null,
            'copy_sources' => $branch === null || $this->viewer === null ? [] : CopyOperationsSetup::sources($this->viewer, $branch),
        ];
    }
}
