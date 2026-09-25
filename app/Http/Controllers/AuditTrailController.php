<?php

namespace App\Http\Controllers;

use App\Http\Requests\AuditTrailRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Inertia\Inertia;
use Inertia\Response;

class AuditTrailController extends Controller
{
    public function __invoke(AuditTrailRequest $request): Response
    {
        $filters = $request->safe()->only(['branch_id', 'user_id', 'module', 'action', 'search', 'date']);
        $logs = AuditLog::query()
            ->with(['branch:id,name,code', 'user:id,name,email,position'])
            ->when($filters['branch_id'] ?? null, fn (Builder $query, string $branchId) => $query->where('branch_id', $branchId))
            ->when($filters['user_id'] ?? null, fn (Builder $query, int $userId) => $query->where('user_id', $userId))
            ->when($filters['module'] ?? null, fn (Builder $query, string $module) => $query->where('module', $module))
            ->when($filters['action'] ?? null, fn (Builder $query, string $action) => $query->where('action', $action))
            ->when($filters['search'] ?? null, function (Builder $query, string $search): void {
                $term = '%'.mb_strtolower(trim($search)).'%';
                $query->where(function (Builder $query) use ($term): void {
                    $query->whereRaw('LOWER(action) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(module) LIKE ?', [$term])
                        ->orWhereRaw('LOWER(auditable_id) LIKE ?', [$term])
                        ->orWhereHas('user', fn (Builder $user) => $user
                            ->whereRaw('LOWER(name) LIKE ?', [$term])
                            ->orWhereRaw('LOWER(email) LIKE ?', [$term]));
                });
            })
            ->when($filters['date'] ?? null, function (Builder $query, string $date): void {
                $day = CarbonImmutable::parse($date, 'Asia/Manila');
                $query->whereBetween('created_at', [$day->startOfDay()->utc(), $day->endOfDay()->utc()]);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(30)
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'created_at' => $log->created_at->toIso8601String(),
                'branch' => $log->branch?->only(['id', 'name', 'code']),
                /** Position is the actor's current Staff title (display only), not a historical snapshot. */
                'actor' => $log->user?->only(['id', 'name', 'email', 'position']),
                'module' => $log->module,
                'action' => $log->action,
                'auditable_type' => class_basename($log->auditable_type),
                'auditable_id' => $log->auditable_id,
                'before' => $log->before,
                'after' => $log->after,
                'metadata' => $log->metadata,
            ]);

        return Inertia::render('super-admin/audit-trail', [
            'logs' => $logs,
            'filters' => $filters,
            'branches' => Branch::query()->orderBy('name')->get(['id', 'name', 'code']),
            'users' => User::query()->orderBy('name')->get(['id', 'name', 'email']),
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'actions' => AuditLog::query()->distinct()->orderBy('action')->pluck('action'),
        ]);
    }
}
