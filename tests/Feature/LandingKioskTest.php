<?php

use App\Enums\BranchStatus;
use App\Models\Branch;
use App\Models\StoreSession;
use Inertia\Testing\AssertableInertia as Assert;

test('the online kiosk lists each active Branch with its real public ordering page and whether it takes orders now', function () {
    $open = Branch::factory()->create(['code' => 'ADARNA', 'name' => 'Adarna St.', 'address' => '1511, Unit V, Purok 8 Adarna St.']);
    StoreSession::factory()->for($open)->create();
    $closed = Branch::factory()->create(['code' => 'DC', 'name' => 'Dona Carmen', 'address' => null]);
    StoreSession::factory()->for($closed)->closed()->create();
    /** The store is open, but this Branch does not take kiosk orders, so the kiosk would say it is closed. */
    $paused = Branch::factory()->create(['code' => 'QAVE', 'name' => 'Q Ave', 'qr_ordering_enabled' => false]);
    StoreSession::factory()->for($paused)->create();
    Branch::factory()->create(['code' => 'OLD', 'name' => 'Old Branch', 'status' => BranchStatus::Inactive]);

    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('welcome')
        ->where('kiosk', [
            ['name' => 'Adarna St.', 'address' => '1511, Unit V, Purok 8 Adarna St.', 'url' => '/kiosk/ADARNA', 'open' => true],
            ['name' => 'Dona Carmen', 'address' => null, 'url' => '/kiosk/DC', 'open' => false],
            ['name' => 'Q Ave', 'address' => $paused->address, 'url' => '/kiosk/QAVE', 'open' => false],
        ])
        ->where('reviewBranches.0', ['id' => $open->id, 'name' => 'Adarna St.', 'code' => 'ADARNA']));
});

test('every kiosk link opens the Branch ordering page, open or closed', function () {
    $closed = Branch::factory()->create(['code' => 'DC']);
    $url = null;

    $this->get(route('home'))->assertInertia(function (Assert $page) use (&$url) {
        $url = $page->toArray()['props']['kiosk'][0]['url'];
    });

    expect($url)->toBe(route('kiosk.show', ['branch' => $closed->kiosk_code], false));
    $this->get($url)->assertOk()->assertInertia(fn (Assert $page) => $page->component('qr/show')->where('store.status', 'closed'));
});

test('without an active Branch the kiosk list is empty and the page still renders', function () {
    $this->get(route('home'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('kiosk', []));
});
