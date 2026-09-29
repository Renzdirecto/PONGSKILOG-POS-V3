<?php

use App\Models\User;

test('a successful login is recorded in the audit trail', function () {
    $user = User::factory()->create();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'password',
    ])->assertRedirect(route('workspace', absolute: false));

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'module' => 'authentication',
        'action' => 'auth.login',
        'auditable_type' => User::class,
        'auditable_id' => (string) $user->id,
    ]);
});
