<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->cashier = User::factory()->create();
    $this->cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $this->cashier->branches()->attach($this->branch, ['is_active' => true]);
    $this->session = StoreSession::factory()->for($this->branch)->create(['opened_by_user_id' => $this->cashier->id]);
    $this->order = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id, 'commercial_status' => 'active', 'payment_status' => 'paid',
        'payment_term' => 'immediate', 'kitchen_status' => 'kitchen', 'committed_at' => now(), 'total' => '100.00',
    ]);
    $this->payment = Payment::factory()->for($this->order)->create([
        'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id, 'created_by_user_id' => $this->cashier->id,
        'method' => 'cashless', 'amount' => '100.00', 'amount_received' => null, 'change_amount' => null,
        'idempotency_key' => Str::uuid().':cashless',
    ]);
});

test('cashier can add view replace and remove a private cashless invoice proof', function () {
    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id]);
    $this->post(route('pos.payments.invoice.store', $this->payment), ['invoice' => UploadedFile::fake()->image('first.jpg', 640, 480)])->assertOk();
    $proof = $this->payment->invoiceProof()->sole();
    Storage::disk('local')->assertExists($proof->path);
    $this->get(route('pos.payments.invoice.show', $this->payment))->assertOk()->assertHeader('Cache-Control', 'no-store, private');

    $oldPath = $proof->path;
    $this->post(route('pos.payments.invoice.store', $this->payment), ['invoice' => UploadedFile::fake()->image('replacement.png', 800, 600)])->assertOk();
    Storage::disk('local')->assertMissing($oldPath);
    expect($this->payment->invoiceProof()->count())->toBe(1);

    $path = $this->payment->invoiceProof()->sole()->path;
    $this->delete(route('pos.payments.invoice.destroy', $this->payment))->assertNoContent();
    Storage::disk('local')->assertMissing($path);
    $this->assertDatabaseCount('payment_invoice_proofs', 0);
    $this->assertDatabaseCount('audit_logs', 3);
});

test('cash payments invalid files and closed sessions cannot mutate invoice proofs', function () {
    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id]);
    $cash = Payment::factory()->for($this->order)->create([
        'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id, 'created_by_user_id' => $this->cashier->id,
        'method' => 'cash', 'idempotency_key' => Str::uuid().':cash',
    ]);
    $this->withHeader('Accept', 'application/json')->post(route('pos.payments.invoice.store', $cash), ['invoice' => UploadedFile::fake()->image('cash.jpg', 640, 480)])->assertUnprocessable();
    $this->post(route('pos.payments.invoice.store', $this->payment), ['invoice' => UploadedFile::fake()->create('fake.jpg', 2, 'text/plain')])->assertUnprocessable()->assertJsonValidationErrors('invoice');
    $this->session->update(['status' => 'closed']);
    $this->withHeader('Accept', 'application/json')->post(route('pos.payments.invoice.store', $this->payment), ['invoice' => UploadedFile::fake()->image('late.jpg', 640, 480)])->assertUnprocessable();
    $this->assertDatabaseCount('payment_invoice_proofs', 0);
});
