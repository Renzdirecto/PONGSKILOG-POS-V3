<?php

namespace App\Support;

use App\Events\AccessControlChanged;
use App\Events\StaffChanged;
use App\Events\UserContextChanged;
use Illuminate\Support\Facades\DB;

/**
 * Post-commit realtime invalidation for identity and access changes (every event is ShouldDispatchAfterCommit, so a
 * rolled-back change never signals). Affected accounts get `user.context_changed` on their own channel; open Access
 * Control and Staff pages get `access_control.changed` / `staff.changed`. Payloads carry no permissions or Staff data:
 * clients always reload the authoritative, authorized state. Backend authorization never depends on these signals.
 */
class AccessRealtime
{
    /** @param int|list<int> $userIds */
    public static function usersChanged(int|array $userIds, string $changeType): void
    {
        foreach (array_values(array_unique(array_map('intval', (array) $userIds))) as $userId) {
            UserContextChanged::dispatch($userId, $changeType);
        }
    }

    /**
     * A Role baseline or Custom Role changed: every account holding one of these Roles revalidates its access.
     *
     * @param  list<int>  $roleIds
     */
    public static function rolesChanged(array $roleIds): void
    {
        self::usersChanged(self::userIdsWithRoles($roleIds), UserContextChanged::ACCESS);
    }

    /** @param array<int, string> $branchIds Branches the changed account was or is assigned to (normalized here). */
    public static function staffChanged(array $branchIds = []): void
    {
        StaffChanged::dispatch(array_values(array_unique(array_map('strval', $branchIds))));
    }

    public static function accessControlChanged(string $reason): void
    {
        AccessControlChanged::dispatch($reason);
    }

    /**
     * @param  list<int>  $roleIds
     * @return list<int>
     */
    public static function userIdsWithRoles(array $roleIds): array
    {
        return $roleIds === [] ? [] : array_values(DB::table('user_roles')->whereIn('role_id', $roleIds)
            ->distinct()->orderBy('user_id')->pluck('user_id')->map(fn ($id): int => (int) $id)->all());
    }

    /**
     * The Branch ids of every assignment row of these accounts (active or not).
     *
     * @param  list<int>  $userIds
     * @return list<string>
     */
    public static function branchIdsOf(array $userIds): array
    {
        return $userIds === [] ? [] : array_values(DB::table('user_branch_assignments')->whereIn('user_id', $userIds)
            ->distinct()->pluck('branch_id')->map(fn ($id): string => (string) $id)->all());
    }
}
