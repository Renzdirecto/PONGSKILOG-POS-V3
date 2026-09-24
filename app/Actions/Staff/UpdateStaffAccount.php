<?php

namespace App\Actions\Staff;

use App\Actions\Audit\AuditRecorder;
use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use App\Notifications\AdminAlert;
use App\Support\AdminNotifier;
use App\Support\EffectivePermissions;
use App\Support\StaffRoles;
use App\Support\UserSessions;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class UpdateStaffAccount
{
    public function __construct(private AuditRecorder $audit) {}

    /**
     * Save one existing account: name, email, its single Role, Branch access, active status and profile picture, in
     * one transaction. The Employee ID is a stable identity and is never changed here.
     *
     * Safety rules, all enforced server-side under row locks:
     * - the actor must still manage the account's current and new Role (an Owner never reaches Owner/Super Admin);
     * - nobody changes their own Role or deactivates themselves here;
     * - at least one active Super Admin always remains. Every Super Admin row plus the actor and target are locked in
     *   id order first, so two admins deactivating or demoting each other serialize and the second sees the first;
     * - a Role change clears Branch access for business-wide Roles, requires an active Branch for operational Roles,
     *   and resets the account's custom access to INHERIT;
     * - deactivation rotates the remember token and ends stored sessions.
     *
     * @param  array{name: string, email: string, role: string, branch_ids?: list<string>, is_active: bool, avatar?: UploadedFile|null, remove_avatar?: bool}  $data
     * @return list<string> the audit actions recorded (empty when nothing changed)
     */
    public function execute(User $actor, User $staff, array $data): array
    {
        $avatarDisk = (string) config('filesystems.staff_avatars_disk', 'local');
        $newAvatarPath = null;

        try {
            return DB::transaction(function () use ($actor, $staff, $data, $avatarDisk, &$newAvatarPath): array {
                $locked = $this->lockAccounts($actor, $staff);
                $actor = $locked->get($actor->getKey());
                $staff = $locked->get($staff->getKey());
                if (! $actor instanceof User || ! $staff instanceof User) {
                    throw new AuthorizationException('This account is no longer available.');
                }

                $manageable = $actor->is_active ? StaffRoles::manageableBy($actor) : [];
                $fullAccess = $manageable === StaffRoles::names();
                if ($manageable === [] || (! $fullAccess && ! StaffRoles::canManage($actor, $staff))) {
                    throw new AuthorizationException('This account may not manage this staff member.');
                }

                $currentRoles = $staff->roles()->pluck('name')->all();
                $currentRole = count($currentRoles) === 1 ? $currentRoles[0] : null;
                $newRole = Role::query()->where('name', $data['role'])->first();
                if ($newRole === null || ! in_array($newRole->name, StaffRoles::names(), true)) {
                    throw ValidationException::withMessages(['role' => 'Choose a valid role.']);
                }
                if (! in_array($newRole->name, $manageable, true)) {
                    throw new AuthorizationException('This account may not assign the '.StaffRoles::label($newRole->name).' role.');
                }

                $roleChanged = $currentRole !== $newRole->name;
                $isActive = (bool) $data['is_active'];
                $statusChanged = $staff->is_active !== $isActive;

                if ($staff->is($actor) && ($roleChanged || ! $isActive)) {
                    throw ValidationException::withMessages([
                        $roleChanged ? 'role' : 'is_active' => 'You cannot change your own role or deactivate your own account.',
                    ]);
                }
                $this->ensureSuperAdminRemains($locked, $staff, in_array('super_admin', $currentRoles, true), $newRole->name, $isActive);

                $branchesBefore = $staff->branches()->wherePivot('is_active', true)->orderBy('branches.code')->get(['branches.id', 'branches.code']);
                $branches = $this->assignableBranches($staff, $newRole->name, $data['branch_ids'] ?? []);
                $branchesChanged = $branchesBefore->pluck('id')->sort()->values()->all() !== $branches->pluck('id')->sort()->values()->all();

                $profileBefore = ['name' => $staff->name, 'email' => $staff->email];
                $profileAfter = ['name' => $data['name'], 'email' => $data['email']];
                $profileChanged = $profileBefore !== $profileAfter;

                $oldAvatarPath = $staff->avatar_path;
                $avatar = $data['avatar'] ?? null;
                $removeAvatar = ($data['remove_avatar'] ?? false) && ! $avatar instanceof UploadedFile && $oldAvatarPath !== null;

                if (! $roleChanged && ! $statusChanged && ! $branchesChanged && ! $profileChanged && ! $avatar instanceof UploadedFile && ! $removeAvatar) {
                    return [];
                }

                $staff->forceFill([...$profileAfter, 'is_active' => $isActive])->save();

                if ($avatar instanceof UploadedFile) {
                    $stored = Storage::disk($avatarDisk)->putFileAs('staff-avatars/'.$staff->id, $avatar, Str::uuid().'.'.($avatar->guessExtension() ?: 'jpg'));
                    if ($stored === false) {
                        throw ValidationException::withMessages(['avatar' => 'The profile picture could not be stored. Try again.']);
                    }
                    $newAvatarPath = $stored;
                    $staff->forceFill(['avatar_path' => $stored])->save();
                } elseif ($removeAvatar) {
                    $staff->forceFill(['avatar_path' => null])->save();
                }

                $overridesReset = [];
                if ($roleChanged) {
                    $overridesReset = array_map(fn ($effect): string => $effect->value, EffectivePermissions::overrides((int) $staff->id));
                    $staff->roles()->sync([$newRole->id]);
                    UserPermissionOverride::query()->where('user_id', $staff->id)->delete();
                }
                if ($branchesChanged) {
                    $staff->branches()->sync($branches->mapWithKeys(fn (Branch $branch): array => [$branch->id => ['is_active' => true]])->all());
                }
                if ($statusChanged && ! $isActive) {
                    UserSessions::invalidate($staff);
                }

                $actions = $this->record($actor, $staff, [
                    'role' => [$roleChanged, $currentRole, $newRole->name, $overridesReset],
                    'branches' => [$branchesChanged, $branchesBefore, $branches],
                    'status' => [$statusChanged, ! $isActive, $isActive],
                    'profile' => [$profileChanged, $profileBefore, $profileAfter],
                    'avatar' => [$avatar instanceof UploadedFile, $removeAvatar, $oldAvatarPath !== null],
                ]);

                if ($oldAvatarPath !== null && ($avatar instanceof UploadedFile || $removeAvatar)) {
                    DB::afterCommit(fn () => Storage::disk($avatarDisk)->delete($oldAvatarPath));
                }

                return $actions;
            });
        } catch (Throwable $exception) {
            if ($newAvatarPath !== null) {
                Storage::disk($avatarDisk)->delete($newAvatarPath);
            }
            if ($exception instanceof UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['email' => 'This email is already used by another account.']);
            }

            throw $exception;
        }
    }

    /**
     * Locks every Super Admin account plus the actor and the target, in id order, and returns them freshly read.
     *
     * @return EloquentCollection<int, User>
     */
    public function lockAccounts(User $actor, User $staff): EloquentCollection
    {
        return User::query()
            ->where(fn ($query) => $query
                ->whereKey([$actor->getKey(), $staff->getKey()])
                ->orWhereIn('id', StaffRoles::superAdminUserIds()))
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');
    }

    /**
     * Rejects a change that would leave no active Super Admin. Only rows locked by lockAccounts() are counted.
     *
     * @param  EloquentCollection<int, User>  $locked
     */
    public function ensureSuperAdminRemains(EloquentCollection $locked, User $staff, bool $isSuperAdmin, string $newRole, bool $isActive): void
    {
        $wasActiveSuperAdmin = $staff->is_active && $isSuperAdmin;
        $staysActiveSuperAdmin = $isActive && $newRole === 'super_admin';
        if (! $wasActiveSuperAdmin || $staysActiveSuperAdmin) {
            return;
        }

        $superAdminIds = StaffRoles::superAdminUserIds()->pluck('user_roles.user_id')->map(fn ($id): int => (int) $id)->all();
        $remaining = $locked->filter(fn (User $user): bool => $user->isNot($staff)
            && $user->is_active
            && in_array((int) $user->id, $superAdminIds, true))->count();

        if ($remaining < 1) {
            throw ValidationException::withMessages([
                $isActive ? 'role' : 'is_active' => 'At least one active Super Admin must remain. Add or activate another Super Admin first.',
            ]);
        }
    }

    /**
     * Operational Roles need at least one active Branch (existing assignments to a Branch that is temporarily closed
     * may be kept); business-wide Roles never keep Branch assignments.
     *
     * @param  array<int, mixed>  $branchIds
     * @return Collection<int, Branch>
     */
    private function assignableBranches(User $staff, string $role, array $branchIds): Collection
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

        $assigned = $staff->branches()->pluck('branches.id')->map(fn ($id): string => (string) $id)->all();
        $branches = Branch::query()
            ->whereKey($branchIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->get(['id', 'code', 'status']);
        $valid = $branches->filter(fn (Branch $branch): bool => $branch->status === BranchStatus::Active || in_array((string) $branch->id, $assigned, true));

        if ($branchIds === [] || $valid->count() !== count($branchIds) || ! $valid->contains(fn (Branch $branch): bool => $branch->status === BranchStatus::Active)) {
            throw ValidationException::withMessages(['branch_ids' => 'Choose at least one active Branch for this role.']);
        }

        return $valid->values();
    }

    /**
     * One audit row per distinct change category (role, Branch access, status, profile, picture), never credentials.
     *
     * @param  array<string, array<int, mixed>>  $changes
     * @return list<string>
     */
    private function record(User $actor, User $staff, array $changes): array
    {
        $identity = ['user_id' => $staff->id, 'employee_id' => $staff->employee_id, 'name' => $staff->name];
        $actions = [];
        $write = function (string $action, ?array $before, ?array $after, array $metadata = []) use ($actor, $staff, $identity, &$actions): void {
            $this->audit->record(
                branch: null,
                actor: $actor,
                module: 'staff',
                action: $action,
                auditableType: User::class,
                auditableId: (string) $staff->id,
                before: $before,
                after: $after,
                metadata: [...$identity, ...$metadata],
            );
            $actions[] = $action;
        };

        [$roleChanged, $roleBefore, $roleAfter, $overridesReset] = $changes['role'];
        [$branchesChanged, $branchesBefore, $branchesAfter] = $changes['branches'];
        if ($roleChanged) {
            $write('staff.role_changed',
                ['role' => $roleBefore, 'branch_codes' => $this->branchCodes($branchesBefore)],
                ['role' => $roleAfter, 'branch_codes' => $this->branchCodes($branchesAfter), 'branch_access' => StaffRoles::isBusinessWide($roleAfter) ? 'business_wide' : 'assigned'],
                ['custom_access_reset' => $overridesReset],
            );
        } elseif ($branchesChanged) {
            $write('staff.branch_access_changed', ['branch_codes' => $this->branchCodes($branchesBefore)], ['branch_codes' => $this->branchCodes($branchesAfter)]);
        }

        [$statusChanged, $deactivated] = $changes['status'];
        if ($statusChanged) {
            $write($deactivated ? 'staff.deactivated' : 'staff.reactivated', ['is_active' => $deactivated], ['is_active' => ! $deactivated]);
        }

        [$profileChanged, $profileBefore, $profileAfter] = $changes['profile'];
        if ($profileChanged) {
            $write('staff.updated', $profileBefore, $profileAfter);
        }

        [$avatarUploaded, $avatarRemoved, $hadAvatar] = $changes['avatar'];
        if ($avatarUploaded) {
            $write('staff.avatar_updated', ['has_profile_picture' => $hadAvatar], ['has_profile_picture' => true]);
        } elseif ($avatarRemoved) {
            $write('staff.avatar_removed', ['has_profile_picture' => true], ['has_profile_picture' => false]);
        }

        $this->notify($actor, $staff, $roleChanged ? [$roleBefore, $roleAfter] : null, $statusChanged ? ! $deactivated : null, ! $roleChanged && $branchesChanged ? $this->branchCodes($branchesAfter) : null);

        return $actions;
    }

    /**
     * @param  array{0: string|null, 1: string}|null  $role
     * @param  list<string>|null  $branchCodes
     */
    private function notify(User $actor, User $staff, ?array $role, ?bool $active, ?array $branchCodes): void
    {
        $changes = array_values(array_filter([
            $role === null ? null : 'role '.($role[0] === null ? 'none' : StaffRoles::label($role[0])).' → '.StaffRoles::label($role[1]),
            $active === null ? null : ($active ? 'reactivated' : 'deactivated'),
            $branchCodes === null ? null : 'Branch access '.($branchCodes === [] ? 'cleared' : implode(', ', $branchCodes)),
        ]));
        if ($changes === []) {
            return;
        }

        AdminNotifier::superAdmins(new AdminAlert(
            'staff',
            $active === false ? $staff->name.' was deactivated' : ($role !== null ? $staff->name.'’s role changed' : 'Staff access changed for '.$staff->name),
            $actor->name.' updated '.$staff->name.' ('.($staff->employee_id ?? 'no Employee ID').'): '.implode('; ', $changes).'.',
            route('super-admin.staff.index', ['search' => $staff->employee_id ?? $staff->email], false),
        ), except: $actor);
    }

    /**
     * @param  Collection<int, Branch>  $branches
     * @return list<string>
     */
    private function branchCodes(Collection $branches): array
    {
        return array_values($branches->map(fn (Branch $branch): string => $branch->code)->all());
    }
}
