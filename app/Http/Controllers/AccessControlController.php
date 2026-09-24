<?php

namespace App\Http\Controllers;

use App\Actions\AccessControl\UpdateRolePermissions;
use App\Actions\AccessControl\UpdateUserPermissionOverrides;
use App\Http\Requests\AccessControlRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Requests\UpdateUserPermissionOverridesRequest;
use App\Models\Branch;
use App\Models\User;
use App\Support\EffectivePermissions;
use App\Support\PermissionCatalog;
use App\Support\StaffRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccessControlController extends Controller
{
    /**
     * Role baselines and per-account custom access, loaded with a fixed number of queries (no Role × Permission × User
     * lookups). Metadata comes from the one PermissionCatalog; the browser only renders it.
     */
    public function index(AccessControlRequest $request): Response
    {
        $filters = $request->validated();
        $baselines = EffectivePermissions::roleBaselines();
        $search = trim((string) ($filters['search'] ?? ''));

        $staff = User::query()
            ->select(['id', 'employee_id', 'name', 'email', 'is_active'])
            ->with('roles:id,name')
            ->withCount('permissionOverrides')
            ->whereHas('roles')
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(employee_id) LIKE ?', [$term]));
            })
            ->orderBy('name')
            ->orderBy('id')
            ->limit(50)
            ->get()
            ->map(function (User $user): array {
                $role = $this->singleRole($user);

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'employee_id' => $user->employee_id,
                    'is_active' => $user->is_active,
                    'role' => $role,
                    'role_label' => $role === null ? 'No single role' : StaffRoles::label($role),
                    'custom_count' => (int) $user->permission_overrides_count,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('super-admin/access-control', [
            'permissions' => PermissionCatalog::present(),
            'categories' => PermissionCatalog::CATEGORIES,
            'roles' => array_map(fn (string $role): array => [
                'name' => $role,
                'label' => StaffRoles::label($role),
                'kind' => match (true) {
                    $role === PermissionCatalog::SUPER_ADMIN => 'locked',
                    $role === PermissionCatalog::DERIVED_ROLE => 'derived',
                    default => 'editable',
                },
                'business_wide' => StaffRoles::isBusinessWide($role),
                'permissions' => $baselines[$role] ?? [],
                'locks' => $this->locks($role),
            ], PermissionCatalog::ROLES),
            'staff' => $staff,
            'selected' => isset($filters['user']) ? $this->selected((int) $filters['user'], $baselines) : null,
            'filters' => [
                'tab' => $filters['tab'] ?? (isset($filters['user']) ? 'staff' : 'roles'),
                'role' => $filters['role'] ?? null,
                'search' => $search,
            ],
        ]);
    }

    public function updateRole(UpdateRolePermissionsRequest $request, string $role, UpdateRolePermissions $update): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        $changed = $update->execute($actor, $role, $permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? StaffRoles::label($role).' access saved.' : 'No changes to save.']);

        return to_route('super-admin.access-control', ['tab' => 'roles', 'role' => $role]);
    }

    public function updateUser(UpdateUserPermissionOverridesRequest $request, User $user, UpdateUserPermissionOverrides $update): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var array<string, string> $overrides */
        $overrides = $request->validated('overrides');
        $changed = $update->execute($actor, $user, $overrides);

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? 'Custom access saved.' : 'No changes to save.']);

        return to_route('super-admin.access-control', ['tab' => 'staff', 'user' => $user->id]);
    }

    public function resetUser(AccessControlRequest $request, User $user, UpdateUserPermissionOverrides $update): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $changed = $update->reset($actor, $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? 'Custom access removed. The account follows its role again.' : 'This account already follows its role.']);

        return to_route('super-admin.access-control', ['tab' => 'staff', 'user' => $user->id]);
    }

    /**
     * @param  array<string, list<string>>  $baselines
     * @return array<string, mixed>|null
     */
    private function selected(int $userId, array $baselines): ?array
    {
        $user = User::query()
            ->with([
                'roles:id,name',
                'branches' => fn ($query) => $query
                    ->select(['branches.id', 'branches.name', 'branches.code'])
                    ->wherePivot('is_active', true)
                    ->orderBy('branches.name'),
            ])
            ->find($userId, ['id', 'employee_id', 'name', 'email', 'is_active']);
        if ($user === null) {
            return null;
        }
        $role = $this->singleRole($user);
        $superAdmin = $user->roles->contains('name', PermissionCatalog::SUPER_ADMIN);

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'employee_id' => $user->employee_id,
            'is_active' => $user->is_active,
            'role' => $role,
            'role_label' => $role === null ? 'No single role' : StaffRoles::label($role),
            'business_wide' => $role !== null && StaffRoles::isBusinessWide($role),
            'branches' => $user->branches->map(fn (Branch $branch): array => $branch->only(['id', 'name', 'code']))->values()->all(),
            'lock_reason' => match (true) {
                $superAdmin => 'Super Admin is locked to full access. Custom access cannot remove anything from a Super Admin.',
                $role === null => 'Custom access needs an account with exactly one staff role. Fix the role in Staff first.',
                default => null,
            },
            'baseline' => $role === null ? [] : ($baselines[$role] ?? []),
            'overrides' => array_map(fn ($effect): string => $effect->value, EffectivePermissions::overrides((int) $user->id)),
            'effective' => EffectivePermissions::names($user),
            'locks' => $role === null ? [] : $this->locks($role),
        ];
    }

    /** @return array<string, string|null> */
    private function locks(string $role): array
    {
        return collect(PermissionCatalog::names())
            ->mapWithKeys(fn (string $permission): array => [$permission => PermissionCatalog::lockReason($role, $permission)])
            ->all();
    }

    private function singleRole(User $user): ?string
    {
        $roles = $user->roles->pluck('name')->all();

        return count($roles) === 1 && in_array($roles[0], StaffRoles::names(), true) ? $roles[0] : null;
    }
}
