<?php

use App\Enums\PermissionOverrideEffect;
use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\ShippySetting;
use App\Models\User;
use App\Models\UserPermissionOverride;
use Carbon\CarbonImmutable;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

const SHIPPY_SETTINGS_KEY = 'owner-must-never-see-this-key';

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

function shippySettingsUser(string $role): User
{
    $user = User::factory()->create();
    $user->roles()->attach(Role::query()->where('name', $role)->sole());

    return $user;
}

test('the Owner and Super Admin switch the chat off and on and each change is audited', function (string $role) {
    $manager = shippySettingsUser($role);

    $this->actingAs($manager)->putJson(route('workspaces.website.shippy.update'), ['is_enabled' => false])
        ->assertOk()->assertExactJson(['saved' => true]);

    expect(ShippySetting::current()->is_enabled)->toBeFalse();
    $audit = AuditLog::query()->where('action', 'shippy.disabled')->sole();
    expect($audit->user_id)->toBe($manager->id)->and($audit->module)->toBe('settings')
        ->and($audit->before)->toBe(['is_enabled' => true])->and($audit->after)->toBe(['is_enabled' => false]);
    $this->get(route('workspaces.website-analytics'))->assertInertia(fn (Assert $page) => $page->where('shippy.enabled', false));

    $this->putJson(route('workspaces.website.shippy.update'), ['is_enabled' => true])->assertOk();

    expect(ShippySetting::current()->is_enabled)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'shippy.enabled')->count())->toBe(1);
})->with(['owner', 'super_admin']);

test('saving the switch as it already is changes nothing and records no audit entry', function () {
    $this->actingAs(shippySettingsUser('owner'))->putJson(route('workspaces.website.shippy.update'), ['is_enabled' => true])->assertOk();

    expect(ShippySetting::current()->is_enabled)->toBeTrue()
        ->and(AuditLog::query()->where('auditable_type', ShippySetting::class)->count())->toBe(0);
});

test('a switch that is not yes or no returns 422 and leaves the chat on', function (array $payload) {
    $this->actingAs(shippySettingsUser('owner'))->putJson(route('workspaces.website.shippy.update'), $payload)
        ->assertUnprocessable()->assertJsonValidationErrors('is_enabled');

    expect(ShippySetting::current()->is_enabled)->toBeTrue();
})->with([
    'nothing' => [[]],
    'a word' => [['is_enabled' => 'sometimes']],
]);

test('guests are refused and staff without website access are forbidden from the chat switch', function (string $role) {
    $this->putJson(route('workspaces.website.shippy.update'), ['is_enabled' => false])->assertUnauthorized();

    $this->actingAs(shippySettingsUser($role))->putJson(route('workspaces.website.shippy.update'), ['is_enabled' => false])->assertForbidden();

    expect(ShippySetting::current()->is_enabled)->toBeTrue();
})->with(['cashier', 'kitchen_staff', 'cashier_kitchen']);

test('an Owner who is inactive or whose Settings access was removed cannot switch the chat', function (Closure $restrict) {
    $owner = shippySettingsUser('owner');
    $restrict($owner);

    $response = $this->actingAs($owner)->putJson(route('workspaces.website.shippy.update'), ['is_enabled' => false]);

    expect($response->status())->toBeIn([401, 403])->and(ShippySetting::current()->is_enabled)->toBeTrue();
})->with([
    'inactive' => [fn (User $owner) => $owner->forceFill(['is_active' => false])->save()],
    'Settings denied' => [fn (User $owner) => UserPermissionOverride::query()->create([
        'user_id' => $owner->id, 'permission_id' => Permission::query()->where('name', 'settings.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ])],
]);

test('Website shows real usage with an estimated cost and never the API key', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-14 21:00', 'Asia/Manila'));
    config(['services.gemini.key' => SHIPPY_SETTINGS_KEY, 'services.gemini.requests_per_day' => 300]);
    DB::table('shippy_usage')->insert([
        ['used_on' => '2026-10-14', 'messages' => 40, 'requests' => 25, 'errors' => 2, 'input_tokens' => 1_000_000, 'output_tokens' => 500_000],
        /** The oldest of the thirty days is counted; the day before it is not. */
        ['used_on' => '2026-09-15', 'messages' => 10, 'requests' => 5, 'errors' => 1, 'input_tokens' => 2_000_000, 'output_tokens' => 0],
        ['used_on' => '2026-09-14', 'messages' => 999, 'requests' => 999, 'errors' => 999, 'input_tokens' => 9_000_000, 'output_tokens' => 9_000_000],
    ]);

    $response = $this->actingAs(shippySettingsUser('owner'))->get(route('workspaces.website-analytics'))->assertOk();

    /** $0.10 per million input tokens and $0.40 per million output tokens. */
    $response->assertInertia(fn (Assert $page) => $page
        ->where('can.shippy', true)
        ->where('shippy.enabled', true)->where('shippy.configured', true)->where('shippy.model', 'gemini-2.5-flash-lite')
        ->where('shippy.usage.daily_limit', 300)->where('shippy.usage.days', 30)
        ->where('shippy.usage.today', ['messages' => 40, 'requests' => 25, 'errors' => 2, 'input_tokens' => 1_000_000, 'output_tokens' => 500_000, 'estimated_usd' => 0.3])
        ->where('shippy.usage.month', ['messages' => 50, 'requests' => 30, 'errors' => 3, 'input_tokens' => 3_000_000, 'output_tokens' => 500_000, 'estimated_usd' => 0.5]));
    expect($response->getContent())->not->toContain(SHIPPY_SETTINGS_KEY);
});

test('Website says when no API key is configured and shows zero usage before the first question', function () {
    $this->actingAs(shippySettingsUser('super_admin'))->get(route('workspaces.website-analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('shippy.configured', false)
        ->where('shippy.usage.today', ['messages' => 0, 'requests' => 0, 'errors' => 0, 'input_tokens' => 0, 'output_tokens' => 0, 'estimated_usd' => 0]));
});

test('an account without website content access gets no Shippy tab and no Shippy data', function () {
    $reader = shippySettingsUser('owner');
    UserPermissionOverride::query()->create([
        'user_id' => $reader->id, 'permission_id' => Permission::query()->where('name', 'settings.manage')->value('id'),
        'effect' => PermissionOverrideEffect::Deny,
    ]);

    $this->actingAs($reader)->get(route('workspaces.website-analytics'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('can.shippy', false)->where('shippy', null));
});
