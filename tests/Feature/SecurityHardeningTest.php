<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
});

test('web responses carry baseline security headers and HSTS only over HTTPS', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
        ->assertHeaderMissing('Strict-Transport-Security');

    $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
});

test('a password reset link always points at APP_URL, whatever Host header the request carried', function () {
    Notification::fake();
    config(['app.url' => 'https://pos.pongskilog.test']);
    $user = User::factory()->create(['email' => 'cashier@pongskilog.test']);

    $this->withHeader('Host', 'attacker.example')->post('http://attacker.example/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $url = $notification->toMail($user)->actionUrl;

        return str_starts_with($url, 'https://pos.pongskilog.test/reset-password/') && ! str_contains($url, 'attacker.example');
    });
});

test('private local files are never served by the generic storage routes', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();
});

test('sharing a receipt re-checks POS access, including an Active Branch', function () {
    $branch = Branch::factory()->create();
    $cashier = User::factory()->create();
    $cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $cashier->branches()->attach($branch, ['is_active' => true]);
    $order = Order::factory()->for($branch)->create(['payment_status' => 'paid', 'order_number' => '1001', 'reference_number' => 'MAIN-092226-0001']);
    Payment::factory()->for($order)->create(['paid_at' => now()]);
    $branch->update(['status' => BranchStatus::TemporarilyClosed]);

    $this->actingAs($cashier)->withSession([ActiveBranchContext::SESSION_KEY => $branch->id])
        ->postJson(route('pos.orders.receipt-share', $order))
        ->assertForbidden();
});
