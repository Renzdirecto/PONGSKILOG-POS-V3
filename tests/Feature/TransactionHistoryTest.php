<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Database\Seeders\RbacSeeder;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->seed(RbacSeeder::class);
    $this->branch = Branch::factory()->create();
    $this->cashier = User::factory()->create();
    $this->cashier->roles()->attach(Role::query()->where('name', 'cashier')->sole());
    $this->cashier->branches()->attach($this->branch, ['is_active' => true]);
    $this->session = StoreSession::factory()->for($this->branch)->create(['opened_by_user_id' => $this->cashier->id]);
});

test('cashier sees only committed transactions in the assigned active branch with server pagination and metrics', function () {
    foreach (range(1, 12) as $number) {
        $order = Order::factory()->for($this->branch)->create([
            'store_session_id' => $this->session->id,
            'order_number' => 'TX-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'commercial_status' => 'active', 'payment_status' => $number === 1 ? 'partial' : 'unpaid',
            'payment_term' => 'pay_later', 'kitchen_status' => 'kitchen', 'subtotal' => '100.00', 'total' => '100.00',
            'committed_at' => now()->subMinutes($number), 'version' => 2,
        ]);
        OrderItem::factory()->for($order)->create(['product_name_snapshot' => 'Tapsilog', 'quantity' => 1, 'line_total' => '100.00']);
    }
    Order::factory()->for($this->branch)->create(['order_number' => 'DRAFT-ONLY']);
    $foreign = Branch::factory()->create();
    Order::factory()->for($foreign)->create(['commercial_status' => 'active', 'committed_at' => now()]);

    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspaces/transaction-history')
            ->has('transactions.data', 10)
            ->where('transactions.total', 12)
            ->where('metrics.total', 12)
            ->where('metrics.balance', 1));
});

test('history filters search and payment state on the server and excludes owners', function () {
    $matching = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id, 'order_number' => 'TX-MATCH', 'customer_label' => 'Maria',
        'commercial_status' => 'active', 'payment_status' => 'partial', 'payment_term' => 'pay_later',
        'kitchen_status' => 'ready', 'subtotal' => '80.00', 'total' => '80.00', 'committed_at' => now(),
    ]);
    OrderItem::factory()->for($matching)->create(['line_total' => '80.00']);
    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history', ['search' => 'maria', 'payment_status' => 'balance']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->has('transactions.data', 1)->where('transactions.data.0.id', $matching->id));

    $owner = User::factory()->create();
    $owner->roles()->attach(Role::query()->where('name', 'owner')->sole());
    $owner->branches()->attach($this->branch, ['is_active' => true]);
    $this->actingAs($owner)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history'))->assertForbidden();
});

test('method filter classifies the first grouped attempt instead of accumulated payment rows', function () {
    $split = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id, 'commercial_status' => 'active', 'payment_status' => 'paid',
        'payment_term' => 'immediate', 'kitchen_status' => 'kitchen', 'total' => '100.00', 'committed_at' => now()->subHour(),
    ]);
    $splitAt = now()->subHour();
    $splitGroup = (string) Str::uuid();
    foreach (['cash', 'cashless'] as $method) {
        Payment::factory()->for($split)->create([
            'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id,
            'created_by_user_id' => $this->cashier->id, 'method' => $method, 'amount' => '50.00',
            'amount_received' => $method === 'cash' ? '50.00' : null, 'change_amount' => $method === 'cash' ? '0.00' : null,
            'idempotency_key' => $splitGroup.':'.$method, 'payment_group_id' => $splitGroup, 'payment_context' => 'initial', 'paid_at' => $splitAt,
        ]);
    }
    $cash = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id, 'commercial_status' => 'active', 'payment_status' => 'paid',
        'payment_term' => 'immediate', 'kitchen_status' => 'kitchen', 'total' => '100.00', 'committed_at' => now()->subMinutes(30),
    ]);
    Payment::factory()->for($cash)->create([
        'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id, 'created_by_user_id' => $this->cashier->id,
        'method' => 'cash', 'amount' => '80.00', 'idempotency_key' => Str::uuid().':cash', 'paid_at' => now()->subMinutes(30),
    ]);
    Payment::factory()->for($cash)->create([
        'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id, 'created_by_user_id' => $this->cashier->id,
        'method' => 'cashless', 'amount' => '20.00', 'amount_received' => null, 'change_amount' => null,
        'idempotency_key' => Str::uuid().':cashless', 'payment_context' => 'edit_balance_settlement', 'paid_at' => now(),
    ]);

    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history', ['payment_method' => 'split']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)->where('transactions.data.0.id', $split->id));
    $this->get(route('workspaces.transaction-history', ['payment_method' => 'cash']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)->where('transactions.data.0.id', $cash->id));
});
