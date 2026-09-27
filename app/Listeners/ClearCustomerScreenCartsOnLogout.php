<?php

namespace App\Listeners;

use App\Events\CustomerScreenChanged;
use App\Models\CustomerScreen;
use App\Models\User;
use App\Support\CustomerScreenLiveState;
use Illuminate\Auth\Events\Logout;

/**
 * A cashier signing out leaves no half-built cart on the customer screen. The station pairing itself stays: it belongs
 * to the POS station, not to the account, so the next cashier on that station keeps the same screen.
 */
class ClearCustomerScreenCartsOnLogout
{
    public function __construct(private CustomerScreenLiveState $live) {}

    public function handle(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }
        $userId = (int) $event->user->getKey();

        rescue(function () use ($userId): void {
            $screenIds = $this->live->forgetCartsOf($userId);
            if ($screenIds === []) {
                return;
            }
            $channelKeys = CustomerScreen::query()->whereKey($screenIds)->pluck('channel_key')->all();
            CustomerScreenChanged::dispatch(array_values($channelKeys), 'cart');
        });
    }
}
