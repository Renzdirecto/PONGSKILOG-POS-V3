<?php

namespace App\Actions\Audit;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\User;

class AuditRecorder
{
    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     * @param  array<string, mixed>|null  $metadata
     */
    public function record(
        ?Branch $branch,
        ?User $actor,
        string $module,
        string $action,
        string $auditableType,
        string $auditableId,
        ?array $before = null,
        ?array $after = null,
        ?array $metadata = null,
        ?string $idempotencyKey = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'branch_id' => $branch?->id,
            'user_id' => $actor?->id,
            'module' => $module,
            'action' => $action,
            'auditable_type' => $auditableType,
            'auditable_id' => $auditableId,
            'before' => $this->redact($before),
            'after' => $this->redact($after),
            'metadata' => $this->redact($metadata),
            'idempotency_key' => $idempotencyKey,
        ]);
    }

    /** @param array<string, mixed>|null $values
     * @return array<string, mixed>|null
     */
    private function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach ($values as $key => $value) {
            if (preg_match('/password|secret|token|credential/i', (string) $key) === 1) {
                $values[$key] = '[REDACTED]';
            } elseif (is_array($value)) {
                $values[$key] = $this->redact($value);
            }
        }

        return $values;
    }
}
