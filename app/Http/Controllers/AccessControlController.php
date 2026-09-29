<?php

namespace App\Http\Controllers;

use App\Actions\AccessControl\ArchiveCustomRole;
use App\Actions\AccessControl\CreateCustomRole;
use App\Actions\AccessControl\UpdateCustomRole;
use App\Actions\AccessControl\UpdateRolePermissions;
use App\Actions\AccessControl\UpdateUserPermissionOverrides;
use App\Http\Requests\AccessControlRequest;
use App\Http\Requests\SaveCustomRoleRequest;
use App\Http\Requests\UpdateRolePermissionsRequest;
use App\Http\Requests\UpdateUserPermissionOverridesRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\CustomRoles;
use App\Support\EffectivePermissions;
use App\Support\PermissionCatalog;
use App\Support\StaffRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class AccessControlController extends Controller
{
    /** Custom Role cards list at most this many assigned accounts; the count is always exact. */
    private const MEMBER_LIMIT = 25;

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
            ->with('roles:id,name,label,is_system,scope,archived_at')
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
                    'role' => $role?->name,
                    'role_label' => $role === null ? 'No single role' : $role->displayLabel(),
                    'custom_count' => (int) $user->permission_overrides_count,
                ];
            })
            ->values()
            ->all();

        return Inertia::render('super-admin/access-control', [
            'permissions' => PermissionCatalog::present(),
            'categories' => PermissionCatalog::CATEGORIES,
            'roles' => $this->roles($baselines),
            'scopes' => PermissionCatalog::SCOPES,
            'scopeLocks' => [
                'branch' => PermissionCatalog::scopeLocks('branch'),
                'business' => PermissionCatalog::scopeLocks('business'),
            ],
            'roleNameMax' => CustomRoles::LABEL_MAX,
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

    public function storeCustomRole(SaveCustomRoleRequest $request, CreateCustomRole $create): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        $role = $create->execute($actor, (string) $request->validated('label'), (string) $request->validated('scope'), $permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => $role->displayLabel().' role created.']);

        return to_route('super-admin.access-control', ['tab' => 'roles', 'role' => $role->name]);
    }

    public function updateCustomRole(SaveCustomRoleRequest $request, Role $role, UpdateCustomRole $update): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        /** @var list<string> $permissions */
        $permissions = $request->validated('permissions');
        $changed = $update->execute($actor, $role, (string) $request->validated('label'), (string) $request->validated('scope'), $permissions);

        Inertia::flash('toast', ['type' => 'success', 'message' => $changed ? 'Role saved. Everyone with this role follows it on their next page.' : 'No changes to save.']);

        return to_route('super-admin.access-control', ['tab' => 'roles', 'role' => $role->name]);
    }

    public function archiveCustomRole(AccessControlRequest $request, Role $role, ArchiveCustomRole $archive): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);
        $archived = $archive->execute($actor, $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => $archived ? $role->displayLabel().' role archived.' : 'This role is already archived.']);

        return to_route('super-admin.access-control', ['tab' => 'roles']);
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
     * System roles in canonical order, then Custom Roles (active by name, archived last) with their assigned Staff
     * counts and a bounded member list. Three queries, whatever the number of roles.
     *
     * @param  array<string, list<string>>  $baselines
     * @return list<array<string, mixed>>
     */
    private function roles(array $baselines): array
    {
        $roles = Role::query()
            ->select(['id', 'name', 'label', 'is_system', 'scope', 'archived_at'])
            ->withCount('users')
            ->with(['users' => fn ($query) => $query
                ->select(['users.id', 'users.name', 'users.employee_id', 'users.is_active'])
                ->orderBy('users.name')
                ->orderBy('users.id')
                ->limit(self::MEMBER_LIMIT)])
            ->get();
        $system = collect(PermissionCatalog::ROLES)
            ->map(fn (string $name): ?Role => $roles->firstWhere('name', $name))
            ->filter();
        $custom = $roles
            ->filter(fn (Role $role): bool => $role->isCustom() && $role->scope !== null)
            ->sortBy(fn (Role $role): string => ($role->isArchived() ? '1' : '0').mb_strtolower($role->displayLabel()).'#'.str_pad((string) $role->id, 12, '0', STR_PAD_LEFT));

        return array_values($system->concat($custom)->map(fn (Role $role): array => [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->displayLabel(),
            'kind' => match (true) {
                $role->name === PermissionCatalog::SUPER_ADMIN => 'locked',
                $role->name === PermissionCatalog::DERIVED_ROLE => 'derived',
                $role->isCustom() && $role->isArchived() => 'archived',
                $role->isCustom() => 'custom',
                default => 'editable',
            },
            'scope' => $role->accessScope(),
            'business_wide' => $role->isBusinessWide(),
            'permissions' => $baselines[$role->name] ?? [],
            'locks' => $this->locks($role),
            'assigned_count' => (int) $role->users_count,
            'members' => $role->isCustom() ? $role->users->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'employee_id' => $user->employee_id,
                'is_active' => $user->is_active,
            ])->values()->all() : [],
        ])->all());
    }

    /**
     * @param  array<string, list<string>>  $baselines
     * @return array<string, mixed>|null
     */
    private function selected(int $userId, array $baselines): ?array
    {
        $user = User::query()
            ->with([
                'roles:id,name,label,is_system,scope,archived_at',
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
            'role' => $role?->name,
            'role_label' => $role === null ? 'No single role' : $role->displayLabel(),
            'custom_role' => $role !== null && $role->isCustom(),
            'business_wide' => $role !== null && $role->isBusinessWide(),
            'branches' => $user->branches->map(fn (Branch $branch): array => $branch->only(['id', 'name', 'code']))->values()->all(),
            'lock_reason' => match (true) {
                $superAdmin => 'Super Admin is locked to full access. Custom access cannot remove anything from a Super Admin.',
                $role === null => 'Custom access needs an account with exactly one staff role. Fix the role in Staff first.',
                default => null,
            },
            'baseline' => $role === null ? [] : ($baselines[$role->name] ?? []),
            'overrides' => array_map(fn ($effect): string => $effect->value, EffectivePermissions::overrides((int) $user->id)),
            'effective' => EffectivePermissions::names($user),
            'locks' => $role === null ? [] : $this->locks($role),
        ];
    }

    /** @return array<string, string|null> */
    private function locks(Role $role): array
    {
        return collect(PermissionCatalog::names())
            ->mapWithKeys(fn (string $permission): array => [$permission => PermissionCatalog::lockReason($role, $permission)])
            ->all();
    }

    /**
     * The account's single assignable role (System, or an active Custom Role with a scope), or null.
     */
    private function singleRole(User $user): ?Role
    {
        $role = $user->roles->count() === 1 ? $user->roles->first() : null;

        return $role instanceof Role && $role->isAssignable() ? $role : null;
    }
}
