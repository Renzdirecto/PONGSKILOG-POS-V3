<?php

namespace App\Http\Controllers;

use App\Actions\Staff\CreateStaffAccount;
use App\Enums\BranchStatus;
use App\Http\Requests\StaffIndexRequest;
use App\Http\Requests\StoreStaffRequest;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\StaffRoles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class StaffController extends Controller
{
    /**
     * List login accounts with their role, Branch access and status. Credentials are never projected.
     */
    public function index(StaffIndexRequest $request): Response
    {
        $filters = $request->safe()->only(['search', 'role', 'status']);
        $staff = User::query()
            ->select(['id', 'name', 'email', 'is_active', 'created_at'])
            ->with([
                'roles:id,name',
                'branches' => fn ($query) => $query
                    ->select(['branches.id', 'branches.name', 'branches.code'])
                    ->wherePivot('is_active', true)
                    ->orderBy('branches.name'),
            ])
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(fn (Builder $query) => $query
                    ->whereRaw('LOWER(name) LIKE ?', [$term])
                    ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
            })
            ->when($filters['role'] ?? null, fn (Builder $query, string $role) => $query
                ->whereHas('roles', fn (Builder $roles) => $roles->where('roles.name', $role)))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query
                ->where('is_active', $status === 'active'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(25)
            ->withQueryString()
            ->through(function (User $user): array {
                $roleNames = $user->roles->pluck('name')->all();

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_active' => $user->is_active,
                    'roles' => array_map(fn (string $role): array => [
                        'name' => $role,
                        'label' => StaffRoles::label($role),
                    ], $roleNames),
                    'business_wide' => array_intersect($roleNames, StaffRoles::BUSINESS_WIDE) !== [],
                    'branches' => $user->branches
                        ->map(fn (Branch $branch): array => $branch->only(['id', 'name', 'code']))
                        ->values()
                        ->all(),
                    'created_at' => $user->created_at?->toIso8601String(),
                ];
            });

        $seededRoles = Role::query()->whereIn('name', StaffRoles::names())->pluck('name')->all();

        return Inertia::render('super-admin/staff', [
            'staff' => $staff,
            'filters' => $filters,
            'roles' => array_values(array_filter(
                StaffRoles::options(),
                fn (array $role): bool => in_array($role['name'], $seededRoles, true),
            )),
            'branches' => Branch::query()
                ->where('status', BranchStatus::Active)
                ->orderBy('name')
                ->orderBy('code')
                ->get(['id', 'name', 'code']),
        ]);
    }

    public function store(StoreStaffRequest $request, CreateStaffAccount $createStaffAccount): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        /** @var array{name: string, email: string, password: string, role: string, branch_ids?: list<string>, is_active?: bool} $data */
        $data = $request->safe()->only(['name', 'email', 'password', 'role', 'branch_ids', 'is_active']);
        $createStaffAccount->execute($actor, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Staff account created.']);

        return to_route('super-admin.staff.index');
    }
}
