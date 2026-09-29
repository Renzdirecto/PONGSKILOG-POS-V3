<?php

use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\Role;
use App\Models\StoreSession;
use App\Models\User;
use App\Support\ActiveBranchContext;
use Carbon\CarbonImmutable;
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
            ->where('history_total', 12)
            ->where('metrics.in_kitchen', 12)
            ->where('metrics.preparing', 0)
            ->where('metrics.done', 0)
            ->where('metrics.paid', 0)
            ->where('metrics.pending', 11));
});

test('last seven days includes today and the previous six Manila dates', function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-22 12:00:00', 'Asia/Manila'));

    foreach ([
        'included' => CarbonImmutable::parse('2026-09-16 00:00:00', 'Asia/Manila')->utc(),
        'excluded' => CarbonImmutable::parse('2026-09-15 23:59:59', 'Asia/Manila')->utc(),
    ] as $orderNumber => $committedAt) {
        Order::factory()->for($this->branch)->create([
            'store_session_id' => $this->session->id,
            'order_number' => $orderNumber,
            'commercial_status' => 'active',
            'payment_status' => 'unpaid',
            'payment_term' => 'pay_later',
            'kitchen_status' => 'kitchen',
            'subtotal' => '100.00',
            'total' => '100.00',
            'committed_at' => $committedAt,
        ]);
    }

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history', ['date' => 'last_7_days']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.total', 1)
            ->where('transactions.data.0.order_number', 'included'));
});

test('kpi counts remain authoritative when a status kpi filter is active', function () {
    foreach (['kitchen', 'preparing', 'done'] as $status) {
        Order::factory()->for($this->branch)->create([
            'store_session_id' => $this->session->id,
            'commercial_status' => 'active',
            'payment_status' => $status === 'done' ? 'paid' : 'unpaid',
            'payment_term' => 'pay_later',
            'kitchen_status' => $status,
            'subtotal' => '100.00',
            'total' => '100.00',
            'committed_at' => now(),
        ]);
    }

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history', ['kitchen_status' => 'preparing']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.total', 1)
            ->where('metrics.in_kitchen', 1)
            ->where('metrics.preparing', 1)
            ->where('metrics.done', 1)
            ->where('metrics.paid', 1)
            ->where('metrics.pending', 2));
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

test('transaction details include the canonical receipt projection', function () {
    $order = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id,
        'order_number' => 'TX-RECEIPT',
        'commercial_status' => 'active',
        'payment_status' => 'unpaid',
        'payment_term' => 'pay_later',
        'kitchen_status' => 'kitchen',
        'subtotal' => '100.00',
        'total' => '100.00',
        'committed_at' => now(),
    ]);
    OrderItem::factory()->for($order)->create([
        'product_name_snapshot' => 'Tapsilog',
        'quantity' => 1,
        'line_total' => '100.00',
    ]);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->getJson(route('pos.transactions.show', $order))
        ->assertOk()
        ->assertJsonPath('transaction.receipt.order_number', 'TX-RECEIPT')
        ->assertJsonPath('transaction.receipt.payment_status', 'unpaid')
        ->assertJsonPath('transaction.receipt.items.0.name', 'Tapsilog');
});

test('voided transactions disappear from cashier history and cannot be reopened', function () {
    $visible = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id,
        'order_number' => 'TX-VISIBLE',
        'commercial_status' => 'active',
        'payment_status' => 'paid',
        'committed_at' => now(),
    ]);
    $voided = Order::factory()->for($this->branch)->create([
        'store_session_id' => $this->session->id,
        'order_number' => 'TX-VOIDED',
        'commercial_status' => 'voided',
        'payment_status' => 'paid',
        'committed_at' => now()->subMinute(),
        'voided_at' => now(),
    ]);

    $this->actingAs($this->cashier)
        ->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('transactions.total', 1)
            ->where('history_total', 1)
            ->where('transactions.data.0.id', $visible->id));

    $this->getJson(route('pos.transactions.show', $voided))->assertNotFound();
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
    $sameSecond = now()->subMinutes(30)->startOfSecond();
    Payment::factory()->for($cash)->create([
        'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id, 'created_by_user_id' => $this->cashier->id,
        'method' => 'cash', 'amount' => '80.00', 'idempotency_key' => Str::uuid().':cash', 'payment_context' => 'initial', 'paid_at' => $sameSecond,
    ]);
    Payment::factory()->for($cash)->create([
        'branch_id' => $this->branch->id, 'store_session_id' => $this->session->id, 'created_by_user_id' => $this->cashier->id,
        'method' => 'cashless', 'amount' => '20.00', 'amount_received' => null, 'change_amount' => null,
        'idempotency_key' => Str::uuid().':cashless', 'payment_context' => 'edit_balance_settlement', 'paid_at' => $sameSecond,
    ]);

    $this->actingAs($this->cashier)->withSession([ActiveBranchContext::SESSION_KEY => $this->branch->id])
        ->get(route('workspaces.transaction-history', ['payment_method' => 'split']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)->where('transactions.data.0.id', $split->id));
    $this->get(route('workspaces.transaction-history', ['payment_method' => 'cash']))
        ->assertOk()->assertInertia(fn (Assert $page) => $page->where('transactions.total', 1)->where('transactions.data.0.id', $cash->id));
});
