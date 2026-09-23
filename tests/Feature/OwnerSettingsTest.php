<?php

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create(['code' => 'ALPHA', 'name' => 'Alpha Branch', 'receipt_footer' => 'Salamat po!']);
});

function settingsUser(string $role, ?Branch $branch = null): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());
    if ($branch !== null) {
        $user->branches()->attach($branch, ['is_active' => true]);
    }

    return $user;
}

test('owner and super admin reuse the one settings page with branch qr and receipt settings', function (string $role) {
    $this->actingAs(settingsUser($role))
        ->get(route('branches.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('branches/index')
            ->where('branches.0.code', 'ALPHA')
            ->where('branches.0.receipt_footer', 'Salamat po!')
            ->has('branches.0.qr_url')
            ->has('branches.0.qr_ordering_enabled'));
})->with(['owner', 'super_admin']);

test('the owner saves receipt and customer qr settings through the existing audited endpoint', function () {
    Event::fake();
    $owner = settingsUser('owner');

    $this->actingAs($owner)
        ->putJson(route('branches.qr-settings.update', $this->branch), ['receipt_footer' => 'Come again!', 'qr_ordering_enabled' => false])
        ->assertOk()
        ->assertExactJson(['saved' => true]);

    expect($this->branch->fresh()->receipt_footer)->toBe('Come again!')
        ->and($this->branch->fresh()->qr_ordering_enabled)->toBeFalse()
        ->and(AuditLog::query()->where('action', 'branch_receipt_qr_settings.updated')->sole()->user_id)->toBe($owner->id);
});

test('operational staff cannot open or change settings', function (string $role) {
    $user = settingsUser($role, $this->branch);

    $this->actingAs($user)->get(route('branches.index'))->assertForbidden();
    $this->actingAs($user)
        ->putJson(route('branches.qr-settings.update', $this->branch), ['receipt_footer' => 'Hacked'])
        ->assertForbidden();

    expect($this->branch->fresh()->receipt_footer)->toBe('Salamat po!');
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);
