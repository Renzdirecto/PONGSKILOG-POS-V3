<?php

namespace App\Actions\Staff;

use App\Actions\Audit\AuditRecorder;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Support\StaffRoles;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateStaffAccount
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Create a login account, its single canonical Role, its Branch assignments and one Audit record atomically.
     * The temporary password is hashed by the User cast and never leaves this method in any other form.
     *
     * @param  array{name: string, email: string, password: string, role: string, branch_ids?: list<string>, is_active?: bool}  $data
     */
    public function execute(User $actor, array $data): User
    {
        try {
            return DB::transaction(function () use ($actor, $data): User {
                $actor = User::query()->whereKey($actor->getKey())->first();
                if ($actor === null || ! $actor->is_active || ! $actor->hasPermission('access_control.manage')) {
                    throw new AuthorizationException('Only Super Admin access control may create staff accounts.');
                }

                $role = Role::query()->where('name', $data['role'])->first();
                if ($role === null || ! in_array($role->name, StaffRoles::names(), true)) {
                    throw ValidationException::withMessages(['role' => 'Choose a valid role.']);
                }

                $branches = $this->assignableBranches($role->name, $data['branch_ids'] ?? []);

                $user = new User;
                $user->forceFill([
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'password' => $data['password'],
                    'is_active' => $data['is_active'] ?? true,
                ])->save();

                $user->roles()->attach($role);
                $user->branches()->attach($branches->mapWithKeys(fn (Branch $branch): array => [
                    $branch->id => ['is_active' => true],
                ])->all());

                $this->audit->record(
                    branch: null,
                    actor: $actor,
                    module: 'staff',
                    action: 'staff.created',
                    auditableType: User::class,
                    auditableId: (string) $user->id,
                    after: [
                        'user_id' => $user->id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $role->name,
                        'branch_access' => StaffRoles::isBusinessWide($role->name) ? 'business_wide' : 'assigned',
                        'branch_ids' => $branches->pluck('id')->values()->all(),
                        'branch_codes' => $branches->pluck('code')->values()->all(),
                        'is_active' => $user->is_active,
                    ],
                );

                return $user;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['email' => 'This email is already used by another account.']);
        }
    }

    /**
     * Operational roles need at least one currently active Branch; business-wide roles never receive fabricated ones.
     *
     * @param  array<int, mixed>  $branchIds
     * @return Collection<int, Branch>
     */
    private function assignableBranches(string $role, array $branchIds): Collection
    {
        $branchIds = array_values(array_unique(array_map('strval', $branchIds)));

        if (StaffRoles::isBusinessWide($role)) {
            if ($branchIds !== []) {
                throw ValidationException::withMessages([
                    'branch_ids' => 'Owner and Super Admin accounts have business-wide access and do not take Branch assignments.',
                ]);
            }

            return collect();
        }

        $branches = Branch::query()
            ->whereKey($branchIds)
            ->where('status', BranchStatus::Active)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code']);

        if ($branchIds === [] || $branches->count() !== count($branchIds)) {
            throw ValidationException::withMessages(['branch_ids' => 'Choose at least one active Branch for this role.']);
        }

        return $branches;
    }
}
