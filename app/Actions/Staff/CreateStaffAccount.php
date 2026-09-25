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
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class CreateStaffAccount
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Create a login account, its single canonical Role, its Branch assignments and one Audit record atomically.
     * The temporary password is hashed by the User cast and never leaves this method in any other form. An optional
     * profile picture is stored on the private staff avatar disk and removed again if the transaction fails.
     *
     * @param  array{employee_id: string, name: string, email: string, position?: string|null, password: string, role: string, branch_ids?: list<string>, is_active?: bool, avatar?: UploadedFile|null}  $data
     */
    public function execute(User $actor, array $data): User
    {
        $avatarDisk = (string) config('filesystems.staff_avatars_disk', 'local');
        $avatarPath = null;

        try {
            return DB::transaction(function () use ($actor, $data, $avatarDisk, &$avatarPath): User {
                $actor = User::query()->whereKey($actor->getKey())->first();
                $manageable = $actor === null ? [] : StaffRoles::manageableBy($actor);
                if ($actor === null || $manageable === []) {
                    throw new AuthorizationException('Only Super Admin access control or Owner Staff management may create staff accounts.');
                }

                /** The shared lock serializes with archiving a Custom Role, which locks the same row FOR UPDATE. */
                $role = Role::query()->where('name', $data['role'])->sharedLock()->first();
                if ($role === null || ! $role->isAssignable()) {
                    throw ValidationException::withMessages(['role' => 'Choose a valid role.']);
                }
                /** Owner Staff management reaches operational roles only; the role list is re-checked here, not trusted. */
                if (! in_array($role->name, $manageable, true)) {
                    throw new AuthorizationException('This account may not create '.$role->displayLabel().' accounts.');
                }

                $branches = $this->assignableBranches($role, $data['branch_ids'] ?? []);

                $user = new User;
                $user->forceFill([
                    'employee_id' => $data['employee_id'],
                    'name' => $data['name'],
                    'email' => $data['email'],
                    'position' => $data['position'] ?? null,
                    'password' => $data['password'],
                    'is_active' => $data['is_active'] ?? true,
                ])->save();

                $avatar = $data['avatar'] ?? null;
                if ($avatar instanceof UploadedFile) {
                    $fileName = Str::uuid().'.'.($avatar->guessExtension() ?: 'jpg');
                    $stored = Storage::disk($avatarDisk)->putFileAs('staff-avatars/'.$user->id, $avatar, $fileName);
                    if ($stored === false) {
                        throw ValidationException::withMessages(['avatar' => 'The profile picture could not be stored. Try again.']);
                    }
                    $avatarPath = $stored;
                    $user->forceFill(['avatar_path' => $avatarPath])->save();
                }

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
                        'employee_id' => $user->employee_id,
                        'name' => $user->name,
                        'email' => $user->email,
                        'position' => $user->position,
                        'role' => $role->name,
                        'role_label' => $role->displayLabel(),
                        'branch_access' => $role->isBusinessWide() ? 'business_wide' : 'assigned',
                        'branch_ids' => $branches->pluck('id')->values()->all(),
                        'branch_codes' => $branches->pluck('code')->values()->all(),
                        'is_active' => $user->is_active,
                        'has_profile_picture' => $user->avatar_path !== null,
                    ],
                );

                return $user;
            });
        } catch (Throwable $exception) {
            if ($avatarPath !== null) {
                Storage::disk($avatarDisk)->delete($avatarPath);
            }

            if (! $exception instanceof UniqueConstraintViolationException) {
                throw $exception;
            }

            /** The raw message embeds the INSERT column list, so only the parsed violated columns or index are trusted. */
            $employeeIdTaken = in_array('employee_id', $exception->columns, true)
                || $exception->index === 'users_employee_id_unique';

            throw $employeeIdTaken
                ? ValidationException::withMessages(['employee_id' => 'This Employee ID is already used by another account.'])
                : ValidationException::withMessages(['email' => 'This email is already used by another account.']);
        }
    }

    /**
     * Branch roles need at least one currently active Branch; business-wide roles never receive fabricated ones.
     *
     * @param  array<int, mixed>  $branchIds
     * @return Collection<int, Branch>
     */
    private function assignableBranches(Role $role, array $branchIds): Collection
    {
        $branchIds = array_values(array_unique(array_map('strval', $branchIds)));

        if ($role->isBusinessWide()) {
            if ($branchIds !== []) {
                throw ValidationException::withMessages([
                    'branch_ids' => StaffRoles::branchesProhibitedMessage($role),
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
