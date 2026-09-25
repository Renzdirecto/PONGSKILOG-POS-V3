<?php

use App\Enums\BranchStatus;
use App\Enums\PermissionOverrideEffect;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->main = Branch::factory()->create(['name' => 'Main', 'code' => 'MAIN']);
    $this->qave = Branch::factory()->create(['name' => 'Qave', 'code' => 'QAVE']);
    $this->superAdmin = staffAdminUser('super_admin', name: 'Control Admin');
    $this->cashier = staffAdminUser('cashier', $this->main, 'Juan Dela Cruz');
    $this->cashier->forceFill(['employee_id' => '09242601', 'email' => 'juan@pongskilog.test'])->save();
});

function staffAdminUser(string $role, ?Branch $branch = null, string $name = 'Staff Member', bool $active = true): User
{
    $user = User::factory()->create(['name' => $name, 'is_active' => $active]);
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

/** @return array<string, mixed> */
function staffEdit(User $user, array $overrides = []): array
{
    $role = $user->roles()->value('name');

    return [
        'name' => $user->name,
        'email' => $user->email,
        'role' => $role,
        'branch_ids' => $user->branches()->pluck('branches.id')->all(),
        'is_active' => $user->is_active,
        ...$overrides,
    ];
}

function allowReports(User $user): void
{
    UserPermissionOverride::query()->create([
        'user_id' => $user->id,
        'permission_id' => Permission::query()->where('name', 'reports.view')->value('id'),
        'effect' => PermissionOverrideEffect::Allow,
    ]);
}

test('super admin edits a cashier name, email and branches while the employee id stays fixed', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, [
            'name' => 'Juan D. Cruz',
            'email' => 'JUAN.CRUZ@pongskilog.test',
            'employee_id' => '01010199',
            'branch_ids' => [$this->qave->id],
        ]))
        ->assertRedirect(route('super-admin.staff.index'));

    $this->cashier->refresh();
    expect($this->cashier->name)->toBe('Juan D. Cruz')
        ->and($this->cashier->email)->toBe('juan.cruz@pongskilog.test')
        ->and($this->cashier->employee_id)->toBe('09242601')
        ->and($this->cashier->branches()->pluck('code')->all())->toBe(['QAVE'])
        ->and(AuditLog::query()->where('auditable_id', (string) $this->cashier->id)->orderBy('action')->pluck('action')->all())
        ->toBe(['staff.branch_access_changed', 'staff.updated']);

    $branchAudit = AuditLog::query()->where('action', 'staff.branch_access_changed')->sole();
    expect($branchAudit->before['branch_codes'])->toBe(['MAIN'])
        ->and($branchAudit->after['branch_codes'])->toBe(['QAVE']);
});

test('saving without changes records nothing', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier))
        ->assertRedirect();

    expect(AuditLog::query()->where('module', 'staff')->exists())->toBeFalse();
});

test('email must stay unique ignoring case', function () {
    staffAdminUser('cashier', $this->main)->forceFill(['email' => 'taken@pongskilog.test'])->save();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['email' => 'Taken@Pongskilog.test']))
        ->assertSessionHasErrors('email');
});

test('an operational role requires at least one active branch', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['branch_ids' => []]))
        ->assertSessionHasErrors('branch_ids');

    $closed = Branch::factory()->create(['status' => BranchStatus::Inactive]);
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['branch_ids' => [$closed->id]]))
        ->assertSessionHasErrors('branch_ids');

    expect($this->cashier->branches()->pluck('code')->all())->toBe(['MAIN']);
});

test('promoting to owner clears branch assignments and resets custom access', function () {
    allowReports($this->cashier);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['role' => 'owner', 'branch_ids' => null]))
        ->assertRedirect();

    $this->cashier->refresh();
    expect($this->cashier->roles()->pluck('name')->all())->toBe(['owner'])
        ->and($this->cashier->branches()->count())->toBe(0)
        ->and(UserPermissionOverride::query()->where('user_id', $this->cashier->id)->exists())->toBeFalse();

    $audit = AuditLog::query()->where('action', 'staff.role_changed')->sole();
    expect($audit->before)->toBe(['role' => 'cashier', 'role_label' => 'Cashier', 'branch_codes' => ['MAIN']])
        ->and($audit->after['role'])->toBe('owner')
        ->and($audit->after['branch_access'])->toBe('business_wide')
        ->and($audit->metadata['custom_access_reset'])->toBe(['reports.view' => 'allow']);
});

test('a business-wide role rejects branch assignments', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['role' => 'owner']))
        ->assertSessionHasErrors('branch_ids');
});

test('demoting an owner to an operational role requires an explicit branch', function () {
    $owner = staffAdminUser('owner');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $owner), staffEdit($owner, ['role' => 'cashier', 'branch_ids' => []]))
        ->assertSessionHasErrors('branch_ids');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $owner), staffEdit($owner, ['role' => 'cashier', 'branch_ids' => [$this->main->id]]))
        ->assertRedirect();

    expect($owner->refresh()->roles()->pluck('name')->all())->toBe(['cashier'])
        ->and($owner->branches()->pluck('code')->all())->toBe(['MAIN']);
});

test('deactivating an account denies its next request and reactivation restores it', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['is_active' => false]))
        ->assertRedirect();

    expect($this->cashier->refresh()->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'staff.deactivated')->exists())->toBeTrue();
    $this->actingAs($this->cashier)->get(route('workspace'))->assertRedirectToRoute('login');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['is_active' => true]))
        ->assertRedirect();

    expect($this->cashier->refresh()->is_active)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'staff.reactivated')->exists())->toBeTrue()
        ->and(User::query()->whereKey($this->cashier->id)->exists())->toBeTrue();
});

test('deactivation rotates the remember token and deletes database sessions of that account only', function () {
    config(['session.driver' => 'database', 'session.connection' => null]);
    $oldToken = 'old-remember-token';
    $this->cashier->forceFill(['remember_token' => $oldToken])->save();
    DB::table('sessions')->insert([
        ['id' => 'juan-session', 'user_id' => $this->cashier->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
        ['id' => 'admin-session', 'user_id' => $this->superAdmin->id, 'ip_address' => null, 'user_agent' => null, 'payload' => '', 'last_activity' => time()],
    ]);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['is_active' => false]));

    expect($this->cashier->refresh()->remember_token)->not->toBe($oldToken)
        ->and(DB::table('sessions')->where('user_id', $this->cashier->id)->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'admin-session')->exists())->toBeTrue();
});

test('avatar can be replaced and removed', function () {
    Storage::fake('local');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, [
            'avatar' => UploadedFile::fake()->image('juan.png', 200, 200),
        ]))
        ->assertRedirect();
    $first = $this->cashier->refresh()->avatar_path;
    Storage::disk('local')->assertExists($first);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, [
            'avatar' => UploadedFile::fake()->image('juan-2.png', 200, 200),
        ]));
    $second = $this->cashier->refresh()->avatar_path;
    Storage::disk('local')->assertExists($second);
    Storage::disk('local')->assertMissing($first);

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['remove_avatar' => true]));
    expect($this->cashier->refresh()->avatar_path)->toBeNull();
    Storage::disk('local')->assertMissing($second);
    expect(AuditLog::query()->where('module', 'staff')->pluck('action')->all())
        ->toBe(['staff.avatar_updated', 'staff.avatar_updated', 'staff.avatar_removed']);
});

test('owner edits operational staff but never owner or super admin accounts', function () {
    $owner = staffAdminUser('owner');
    $otherOwner = staffAdminUser('owner');

    $this->actingAs($owner)
        ->put(route('staff.update', $this->cashier), staffEdit($this->cashier, ['role' => 'kitchen_staff']))
        ->assertRedirect(route('staff.index'));
    expect($this->cashier->refresh()->roles()->pluck('name')->all())->toBe(['kitchen_staff']);

    $this->actingAs($owner)->put(route('staff.update', $otherOwner), staffEdit($otherOwner, ['name' => 'Renamed']))->assertForbidden();
    $this->actingAs($owner)->put(route('staff.update', $this->superAdmin), staffEdit($this->superAdmin, ['is_active' => false]))->assertForbidden();
    $this->actingAs($owner)
        ->put(route('staff.update', $this->cashier), staffEdit($this->cashier, ['role' => 'super_admin', 'branch_ids' => null]))
        ->assertSessionHasErrors('role');
    $this->actingAs($owner)->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier))->assertForbidden();

    expect($otherOwner->refresh()->name)->not->toBe('Renamed')
        ->and($this->superAdmin->refresh()->is_active)->toBeTrue();
});

test('operational staff cannot edit staff accounts', function (string $role) {
    $actor = staffAdminUser($role, $this->main);

    $this->actingAs($actor)->put(route('staff.update', $this->cashier), staffEdit($this->cashier, ['name' => 'X']))->assertForbidden();
    $this->actingAs($actor)->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['name' => 'X']))->assertForbidden();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('the last active super admin cannot be deactivated or demoted', function () {
    $owner = staffAdminUser('owner');
    $second = staffAdminUser('super_admin', name: 'Second Admin');
    $second->forceFill(['is_active' => false])->save();

    /** The only active Super Admin is the actor, who is also protected from self-demotion. */
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->superAdmin), staffEdit($this->superAdmin, ['is_active' => false]))
        ->assertSessionHasErrors('is_active');
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $this->superAdmin), staffEdit($this->superAdmin, ['role' => 'owner']))
        ->assertSessionHasErrors('role');

    expect($this->superAdmin->refresh()->is_active)->toBeTrue()
        ->and($this->superAdmin->hasRole('super_admin'))->toBeTrue()
        ->and($owner->exists)->toBeTrue();
});

test('a super admin may deactivate another super admin only while one active super admin remains', function () {
    $second = staffAdminUser('super_admin', name: 'Second Admin');

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $second), staffEdit($second, ['is_active' => false]))
        ->assertRedirect();
    expect($second->refresh()->is_active)->toBeFalse();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.update', $second), staffEdit($second, ['is_active' => true]))
        ->assertRedirect();
    $this->actingAs($second->refresh())
        ->put(route('super-admin.staff.update', $this->superAdmin), staffEdit($this->superAdmin, ['role' => 'owner']))
        ->assertRedirect();

    expect($this->superAdmin->refresh()->hasRole('owner'))->toBeTrue();

    $this->actingAs($second)
        ->put(route('super-admin.staff.update', $second), staffEdit($second, ['is_active' => false]))
        ->assertSessionHasErrors('is_active');
    expect($second->refresh()->is_active)->toBeTrue();
});

test('an inactive super admin cannot administer staff', function () {
    $inactive = staffAdminUser('super_admin', active: false);

    $this->actingAs($inactive)
        ->put(route('super-admin.staff.update', $this->cashier), staffEdit($this->cashier, ['name' => 'X']))
        ->assertRedirectToRoute('login');
});

test('super admin resets a staff password without auditing or returning it and ends other sessions', function () {
    $this->cashier->forceFill(['remember_token' => 'remember-me'])->save();

    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.password', $this->cashier), [
            'password' => 'New-Temporary-77',
            'password_confirmation' => 'New-Temporary-77',
        ])
        ->assertRedirect(route('super-admin.staff.index'))
        ->assertSessionMissing('password');

    $this->cashier->refresh();
    expect(Hash::check('New-Temporary-77', $this->cashier->password))->toBeTrue()
        ->and($this->cashier->remember_token)->not->toBe('remember-me');

    $audit = AuditLog::query()->where('action', 'staff.password_reset')->sole();
    $serialized = json_encode([$audit->before, $audit->after, $audit->metadata]);
    expect($serialized)->not->toContain('New-Temporary-77')
        ->and($serialized)->not->toContain($this->cashier->password);
});

test('password reset uses the password rules and requires confirmation', function () {
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.password', $this->cashier), ['password' => 'New-Temporary-77', 'password_confirmation' => 'different'])
        ->assertSessionHasErrors('password');
});

test('only super admin resets passwords and never their own through staff administration', function () {
    $owner = staffAdminUser('owner');

    $this->actingAs($owner)
        ->put(route('super-admin.staff.password', $this->cashier), ['password' => 'New-Temporary-77', 'password_confirmation' => 'New-Temporary-77'])
        ->assertForbidden();
    $this->actingAs($this->superAdmin)
        ->put(route('super-admin.staff.password', $this->superAdmin), ['password' => 'New-Temporary-77', 'password_confirmation' => 'New-Temporary-77'])
        ->assertSessionHasErrors('password');
});

test('an administrative password reset signs the account out of an existing session', function () {
    $this->actingAs($this->cashier)->get(route('profile.edit'))->assertOk();

    $this->cashier->forceFill(['password' => 'Another-Password-9'])->save();

    $this->actingAs($this->cashier)->get(route('profile.edit'))->assertRedirectToRoute('login');
});
