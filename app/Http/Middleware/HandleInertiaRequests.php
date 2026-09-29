<?php

namespace App\Http\Middleware;

use App\Enums\BranchStatus;
use App\Enums\CommercialStatus;
use App\Enums\OrderSource;
use App\Enums\StoreSessionStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\EffectivePermissions;
use App\Support\ReleaseInfo;
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
        if ($request->routeIs('qr.*', 'kiosk.*', 'receipt.*', 'workspaces.customer-display', 'customer-screen.*', 'pickup.*')) {
            return [];
        }

        $authenticatedUser = $request->user();
        $user = $authenticatedUser instanceof User ? $authenticatedUser : null;
        /**
         * Every shared prop is lazy: JSON endpoints, image streams and partial reloads that do not ask for them never run
         * their queries. The selected Branch is resolved at most once per response.
         */
        $resolved = [];
        $currentBranch = function () use ($user, &$resolved): ?Branch {
            if (! array_key_exists('branch', $resolved)) {
                $resolved['branch'] = $user === null ? null : $this->activeBranchContext->current($user);
            }

            return $resolved['branch'];
        };

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => fn (): array => $this->authProps($user),
            'branchContext' => fn (): array => $this->branchContextProps($user, $currentBranch()),
            'storeContext' => fn (): array => $this->storeContextProps($currentBranch(), $user),
            /** Customer QR orders waiting at the selected Branch: the QR Orders badge of every Store Operations page. */
            'qrWaitingCount' => fn (): ?int => $this->qrWaitingCount($user, $currentBranch()),
            'notificationCenter' => fn (): ?array => $this->notificationCenterProps($user),
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            /** The running release (APP_VERSION + deployed commit) so support can tell which build a device runs. */
            'release' => ReleaseInfo::current(),
        ];
    }

    /** @return array{user: array{id: int, name: string, preferredName: string|null, displayName: string, email: string, position: string|null, avatarUrl: string|null}|null, roles: list<string>, roleLabel: string|null, permissions: list<string>} */
    private function authProps(?User $user): array
    {
        if ($user === null) {
            return [
                'user' => null,
                'roles' => [],
                'roleLabel' => null,
                'permissions' => [],
            ];
        }

        $roles = $user->roles()->orderBy('name')->get(['roles.name', 'roles.label']);

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                /** Display only (greetings, shells); the full name stays the account's identity. */
                'preferredName' => $user->preferred_name,
                'displayName' => $user->displayName(),
                'email' => $user->email,
                'position' => $user->position,
                /** Versioned by the stored path, so a new picture shows after a realtime revalidation. */
                'avatarUrl' => $user->avatar_path === null ? null : route('profile.avatar', [], false).'?v='.substr(md5($user->avatar_path), 0, 12),
            ],
            'roles' => array_values($roles->pluck('name')->all()),
            'roleLabel' => $roles->isEmpty() ? null : $roles->map(fn (Role $role): string => $role->displayLabel())->implode(' / '),
            'permissions' => EffectivePermissions::names($user),
        ];
    }

    /**
     * The Control Center unread badge: a real count of the viewer's unread in-app notifications, only for accounts
     * that hold the notification center (Super Admin access control). Never a placeholder number.
     *
     * @return array{unread: int}|null
     */
    private function notificationCenterProps(?User $user): ?array
    {
        if ($user === null || ! $user->is_active || ! $user->hasPermission('access_control.manage')) {
            return null;
        }

        return ['unread' => $user->unreadNotifications()->count()];
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

    /**
     * The shared Store status of the selected Branch. `canOpen` mirrors Open Store's own authorization (POS access, Open /
     * Close Store, a cashier-operations role, operational access to an Active Branch) so every Store Operations page can
     * offer the one Open Store control only to accounts the server will accept; the server still decides.
     *
     * @return array{status: 'open'|'closed'|null, isOpen: bool, branchId: string|null, canOpen: bool}
     */
    private function storeContextProps(?Branch $branch, ?User $user): array
    {
        $status = $branch === null ? null : $this->storeState->status($branch);

        return [
            'status' => $status?->value,
            'isOpen' => $status === StoreSessionStatus::Open,
            'branchId' => $branch === null ? null : (string) $branch->getKey(),
            'canOpen' => $branch !== null && $user !== null && $status !== StoreSessionStatus::Open
                && $branch->status === BranchStatus::Active
                && $user->hasPermission('pos.access') && $user->hasPermission('store.open_close')
                && $user->hasCashierOperationsRole() && $user->hasOperationalBranchAccess($branch),
        ];
    }

    /** Submitted, unclaimed Customer QR orders of the open Store Session; null for accounts without the POS. */
    private function qrWaitingCount(?User $user, ?Branch $branch): ?int
    {
        if ($user === null || $branch === null || ! $user->hasPermission('pos.access')) {
            return null;
        }

        return Order::query()->where('branch_id', $branch->id)
            ->where('source', OrderSource::CustomerQr)->where('commercial_status', CommercialStatus::Submitted)
            ->whereNull('loaded_by_user_id')
            ->whereIn('store_session_id', $branch->storeSessions()->where('status', StoreSessionStatus::Open)->select('id'))
            ->count();
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
