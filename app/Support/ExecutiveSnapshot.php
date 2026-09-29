<?php

namespace App\Support;

use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

/**
 * Non-financial, read-only state for the Super Admin Executive Dashboard: Store status per Branch, empty Ingredient
 * balances, Staff and role counts, and a short, payload-free Audit summary. Every method is a fixed number of aggregate
 * or bounded queries; money never comes from here (SalesAnalytics is the one financial authority).
 */
class ExecutiveSnapshot
{
    private const AUDIT_LIMIT = 6;

    /**
     * Each active Branch in scope with its OPEN Store Session, if any (one query).
     *
     * @return list<array{branch: array{id: string, name: string, code: string}, open: bool, opened_at: string|null, opened_by: string|null}>
     */
    public function stores(?Branch $branch): array
    {
        return array_values(DB::table('branches')
            ->leftJoin('store_sessions', function (JoinClause $join): void {
                $join->on('store_sessions.branch_id', '=', 'branches.id')
                    ->where('store_sessions.status', StoreSessionStatus::Open->value);
            })
            ->leftJoin('users', 'users.id', '=', 'store_sessions.opened_by_user_id')
            ->where('branches.status', BranchStatus::Active->value)
            ->when($branch !== null, fn (QueryBuilder $query) => $query->where('branches.id', $branch?->id))
            ->orderBy('branches.name')
            ->orderBy('branches.code')
            ->get(['branches.id', 'branches.name', 'branches.code', 'store_sessions.opened_at', 'users.name as opened_by'])
            ->map(fn (object $row): array => [
                'branch' => ['id' => (string) $row->id, 'name' => (string) $row->name, 'code' => (string) $row->code],
                'open' => $row->opened_at !== null,
                'opened_at' => $row->opened_at === null ? null : CarbonImmutable::parse((string) $row->opened_at, 'UTC')->setTimezone(ReportPeriod::TIMEZONE)->toIso8601String(),
                'opened_by' => $row->opened_by === null ? null : (string) $row->opened_by,
            ])
            ->all());
    }

    /**
     * Active Ingredients whose canonical Branch balance is at or below zero (the same transition that raises an
     * out-of-stock alert), per active Branch in scope (one grouped query).
     *
     * @return array{total: int, branches: list<array{code: string, count: int}>}
     */
    public function ingredientsOut(?Branch $branch): array
    {
        $rows = DB::table('branch_ingredient_stocks')
            ->join('ingredients', 'ingredients.id', '=', 'branch_ingredient_stocks.ingredient_id')
            ->join('branches', 'branches.id', '=', 'branch_ingredient_stocks.branch_id')
            ->whereNull('ingredients.archived_at')
            ->where('branches.status', BranchStatus::Active->value)
            ->where('branch_ingredient_stocks.on_hand', '<=', 0)
            ->when($branch !== null, fn (QueryBuilder $query) => $query->where('branches.id', $branch?->id))
            ->groupBy('branches.code')
            ->orderBy('branches.code')
            ->get(['branches.code', DB::raw('COUNT(*) AS aggregate')]);

        return [
            'total' => (int) $rows->sum('aggregate'),
            'branches' => array_values($rows->map(fn (object $row): array => ['code' => (string) $row->code, 'count' => (int) $row->aggregate])->all()),
        ];
    }

    /**
     * Staff accounts (logins holding a role) by status, active Super Admins and active Custom Roles (two queries).
     *
     * @return array{active: int, inactive: int, super_admins: int, custom_roles: int}
     */
    public function people(): array
    {
        $counts = DB::table('users')
            ->whereExists(fn (QueryBuilder $roles) => $roles->selectRaw('1')->from('user_roles')->whereColumn('user_roles.user_id', 'users.id'))
            ->selectRaw('SUM(CASE WHEN users.is_active THEN 1 ELSE 0 END) AS active')
            ->selectRaw('SUM(CASE WHEN users.is_active THEN 0 ELSE 1 END) AS inactive')
            ->selectRaw('SUM(CASE WHEN users.is_active AND EXISTS (SELECT 1 FROM user_roles JOIN roles ON roles.id = user_roles.role_id WHERE user_roles.user_id = users.id AND roles.name = ?) THEN 1 ELSE 0 END) AS super_admins', [PermissionCatalog::SUPER_ADMIN])
            ->first();

        return [
            'active' => (int) ($counts->active ?? 0),
            'inactive' => (int) ($counts->inactive ?? 0),
            'super_admins' => (int) ($counts->super_admins ?? 0),
            'custom_roles' => Role::query()->assignableCustom()->count(),
        ];
    }

    /**
     * The viewer's unread notifications and the latest Audit entries: action, actor, Branch and time only. Before /
     * after payloads are never sent here; the Audit Trail shows them to the same Super Admin.
     *
     * @return array{unread: int, audit: list<array{id: string, action: string, module: string, actor: string|null, branch: string|null, at: string}>}
     */
    public function security(User $viewer): array
    {
        return [
            'unread' => $viewer->unreadNotifications()->count(),
            'audit' => array_values(AuditLog::query()
                ->with(['user:id,name', 'branch:id,code'])
                ->latest('created_at')
                ->latest('id')
                ->limit(self::AUDIT_LIMIT)
                ->get(['id', 'user_id', 'branch_id', 'module', 'action', 'created_at'])
                ->map(fn (AuditLog $audit): array => [
                    'id' => (string) $audit->id,
                    'action' => (string) $audit->action,
                    'module' => (string) $audit->module,
                    'actor' => $audit->user?->name,
                    'branch' => $audit->branch?->code,
                    'at' => CarbonImmutable::instance($audit->created_at)->setTimezone(ReportPeriod::TIMEZONE)->toIso8601String(),
                ])
                ->all()),
        ];
    }
}
