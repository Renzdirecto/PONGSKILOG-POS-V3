<?php

namespace App\Http\Middleware;

use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\StoreState;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    public function __construct(
        private ActiveBranchContext $activeBranchContext,
        private StoreState $storeState,
    ) {}

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        if ($request->routeIs('qr.show', 'workspaces.customer-display')) {
            return [];
        }

        $authenticatedUser = $request->user();
        $user = $authenticatedUser instanceof User ? $authenticatedUser : null;
        $currentBranch = $user === null ? null : $this->activeBranchContext->current($user);

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => $this->authProps($user),
            'branchContext' => $this->branchContextProps($user, $currentBranch),
            'storeContext' => fn (): array => $this->storeContextProps($currentBranch),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }

    /** @return array{user: array{id: int, name: string, email: string}|null, roles: list<string>, permissions: list<string>} */
    private function authProps(?User $user): array
    {
        if ($user === null) {
            return [
                'user' => null,
                'roles' => [],
                'permissions' => [],
            ];
        }

        $roles = $user->roles()
            ->with('permissions:id,name')
            ->orderBy('name')
            ->get(['roles.id', 'roles.name']);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ],
            'roles' => array_values($roles
                ->map(fn (Role $role): string => $role->name)
                ->all()),
            'permissions' => array_values($roles
                ->flatMap(fn (Role $role) => $role->permissions
                    ->map(fn (Permission $permission): string => $permission->name))
                ->unique()
                ->sort()
                ->all()),
        ];
    }

    /** @return array{current: array{id: string, name: string, code: string}|null, businessWide: bool, selectableBranches: list<array{id: string, name: string, code: string}>} */
    private function branchContextProps(?User $user, ?Branch $currentBranch): array
    {
        if ($user === null) {
            return [
                'current' => null,
                'businessWide' => false,
                'selectableBranches' => [],
            ];
        }

        $businessWide = $user->hasBusinessWideScope();
        $selectableBranches = $businessWide
            ? Branch::query()
                ->orderBy('name')
                ->orderBy('code')
                ->get(['id', 'name', 'code'])
            : $user->branches()
                ->wherePivot('is_active', true)
                ->orderBy('name')
                ->orderBy('code')
                ->get(['branches.id', 'branches.name', 'branches.code']);

        return [
            'current' => $currentBranch === null ? null : $this->branchProps($currentBranch),
            'businessWide' => $businessWide,
            'selectableBranches' => array_values($selectableBranches
                ->map(fn (Branch $branch): array => $this->branchProps($branch))
                ->all()),
        ];
    }

    /** @return array{status: 'open'|'closed'|null, isOpen: bool, branchId: string|null} */
    private function storeContextProps(?Branch $branch): array
    {
        $status = $branch === null ? null : $this->storeState->status($branch);

        return [
            'status' => $status?->value,
            'isOpen' => $status === StoreSessionStatus::Open,
            'branchId' => $branch === null ? null : (string) $branch->getKey(),
        ];
    }

    /** @return array{id: string, name: string, code: string} */
    private function branchProps(Branch $branch): array
    {
        return [
            'id' => (string) $branch->getKey(),
            'name' => $branch->name,
            'code' => $branch->code,
        ];
    }
}
