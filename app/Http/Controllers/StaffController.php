<?php

namespace App\Http\Controllers;

use App\Actions\Staff\CreateStaffAccount;
use App\Actions\Staff\ResetStaffPassword;
use App\Actions\Staff\UpdateStaffAccount;
use App\Enums\BranchStatus;
use App\Http\Requests\ResetStaffPasswordRequest;
use App\Http\Requests\StaffIndexRequest;
use App\Http\Requests\StoreStaffRequest;
use App\Http\Requests\UpdateStaffRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\StaffRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StaffController extends Controller
{
    /**
     * List login accounts with their role, Branch access and status. Credentials are never projected. Super Admin sees
     * every account; the Owner sees operational Staff only (Cashier, Kitchen Staff, Cashier + Kitchen). A Branch-scoped
     * Staff manager sees only other accounts with an active assignment at one of its own Branches, and never the names
     * of the other Branches such an account also works at (only their count).
     */
    public function index(StaffIndexRequest $request): Response
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $surface = $this->surface($request);
        $manageable = StaffRoles::manageableBy($actor);
        $fullAccess = StaffRoles::managesEveryAccount($actor);
        $branchScope = StaffRoles::branchScope($actor);
        $filters = $request->safe()->only(['search', 'role', 'status']);
        $staff = User::query()
            ->select(['id', 'employee_id', 'name', 'email', 'position', 'is_active', 'avatar_path', 'created_at'])
            ->with([
                'roles:id,name,label,is_system,scope,archived_at',
                'branches' => fn ($query) => $query
                    ->select(['branches.id', 'branches.name', 'branches.code'])
                    ->wherePivot('is_active', true)
                    ->orderBy('branches.name'),
            ])
            ->withCount('permissionOverrides')
            ->when(! $fullAccess, fn (Builder $query) => $this->scopeToManageable($query, $manageable))
            ->when($branchScope !== null, fn (Builder $query) => $query
                ->whereKeyNot($actor->id)
                ->whereHas('branches', fn (Builder $branches) => $branches
                    ->whereIn('branches.id', $branchScope)
                    ->where('user_branch_assignments.is_active', true)))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(position) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(employee_id) LIKE ?', [$term]));
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', $role)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (User $user) use ($surface, $actor, $branchScope): array {
                $visibleBranches = $branchScope === null
                    ? $user->branches
                    : $user->branches->filter(fn (Branch $branch): bool => in_array((string) $branch->id, $branchScope, true));

                return [
                    'id' => $user->id,
                    'employee_id' => $user->employee_id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'position' => $user->position,
                    'avatar_url' => $user->avatar_path === null
                        ? null
                        : route($surface === 'owner' ? 'staff.avatar' : 'super-admin.staff.avatar', $user, false).'?v='.substr(md5($user->avatar_path), 0, 12),
                    'is_active' => $user->is_active,
                    'roles' => $user->roles->map(fn (Role $role): array => [
                        'name' => $role->name,
                        'label' => $role->displayLabel(),
                        'custom' => $role->isCustom(),
                    ])->values()->all(),
                    'business_wide' => $user->roles->contains(fn (Role $role): bool => $role->isBusinessWide()),
                    'branches' => $visibleBranches
                        ->map(fn (Branch $branch): array => $branch->only(['id', 'name', 'code']))
                        ->values()
                        ->all(),
                    /** Active assignments outside the viewer's Branch scope: counted, never named, never editable here. */
                    'other_branch_count' => $user->branches->count() - $visibleBranches->count(),
                    'created_at' => $user->created_at?->toIso8601String(),
                    'is_self' => $user->is($actor),
                    'custom_access_count' => $surface === 'owner' ? 0 : (int) $user->permission_overrides_count,
                ];
            });

        return Inertia::render('super-admin/staff', [
            'staff' => $staff,
            'filters' => $filters,
            'surface' => $surface,
            'roles' => StaffRoles::options($manageable),
            'branches' => Branch::query()
                ->where('status', BranchStatus::Active)
                ->when($branchScope !== null, fn (Builder $query) => $query->whereKey($branchScope))
                ->orderBy('name')
                ->orderBy('code')
                ->get(['id', 'name', 'code']),
            'branchScoped' => $branchScope !== null,
        ]);
    }

    public function store(StoreStaffRequest $request, CreateStaffAccount $createStaffAccount): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        /** @var array{employee_id: string, name: string, email: string, position?: string|null, password: string, role: string, branch_ids?: list<string>, is_active?: bool} $data */
        $data = $request->safe()->only(['employee_id', 'name', 'email', 'position', 'password', 'role', 'branch_ids', 'is_active']);
        $createStaffAccount->execute($actor, [...$data, 'avatar' => $request->file('avatar')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Staff account created.']);

        return to_route($this->surface($request) === 'owner' ? 'staff.index' : 'super-admin.staff.index');
    }

    /**
     * Save an existing account (details, Role, Branch access, status, picture). The Employee ID never changes.
     */
    public function update(UpdateStaffRequest $request, User $user, UpdateStaffAccount $updateStaffAccount): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        /** @var array{name: string, email: string, position?: string|null, role: string, branch_ids?: list<string>, is_active: bool, remove_avatar?: bool} $data */
        $data = $request->safe()->only(['name', 'email', 'position', 'role', 'branch_ids', 'is_active', 'remove_avatar']);
        $actions = $updateStaffAccount->execute($actor, $user, [
            ...$data,
            'is_active' => $request->boolean('is_active'),
            'remove_avatar' => $request->boolean('remove_avatar'),
            'avatar' => $request->file('avatar'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => $actions === [] ? 'No changes to save.' : 'Staff account saved.']);

        return to_route($this->surface($request) === 'owner' ? 'staff.index' : 'super-admin.staff.index', $request->query());
    }

    /**
     * Super Admin administrative password reset. The new temporary password is never returned or shown again.
     */
    public function password(ResetStaffPasswordRequest $request, User $user, ResetStaffPassword $resetStaffPassword): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $resetStaffPassword->execute($actor, $user, (string) $request->validated('password'));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Temporary password set. '.$user->name.' was signed out of other sessions.']);

        return to_route('super-admin.staff.index', $request->query());
    }

    /**
     * Stream a staff profile picture from the private disk to Super Admin access control, or to an Owner for the
     * operational Staff they manage. The file is never public and its storage path is never exposed.
     */
    public function avatar(Request $request, User $user): StreamedResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User && $actor->is_active
            && ($actor->hasPermission('access_control.manage') || StaffRoles::canManage($actor, $user)), 404);
        $disk = Storage::disk((string) config('filesystems.staff_avatars_disk', 'local'));
        abort_if($user->avatar_path === null || ! $disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** 'owner' for the Owner workspace Staff routes, otherwise the Super Admin access-control surface. */
    private function surface(Request $request): string
    {
        return $request->routeIs('staff.*') ? 'owner' : 'super_admin';
    }

    /**
     * Keeps accounts that hold at least one role and only manageable roles.
     *
     * @param  Builder<User>  $query
     * @param  list<string>  $roles
     */
    private function scopeToManageable(Builder $query, array $roles): void
    {
        $query->whereHas('roles', fn (Builder $assigned) => $assigned->whereIn('roles.name', $roles))
            ->whereDoesntHave('roles', fn (Builder $assigned) => $assigned->whereNotIn('roles.name', $roles));
    }
}
