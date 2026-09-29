<?php

namespace App\Listeners;

use App\Actions\Audit\AuditRecorder;
use App\Models\User;
use Illuminate\Auth\Events\Login;

class RecordLoginAudit
{
    public function __construct(private AuditRecorder $audit) {}

    public function handle(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->audit->record(
            branch: null,
            actor: $event->user,
            module: 'authentication',
            action: 'auth.login',
            auditableType: User::class,
            auditableId: (string) $event->user->id,
            metadata: ['guard' => $event->guard, 'remembered' => $event->remember],
        );
    }
}
