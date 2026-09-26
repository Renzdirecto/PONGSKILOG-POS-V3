<?php

namespace App\Actions\CustomerScreens;

use App\Enums\CustomerScreenMode;
use App\Events\CustomerScreenChanged;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\User;
use App\Support\CustomerScreens;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Presses one of the two Store Operations controls for this station's paired screen. The decision is made under the
 * screen's row lock from the stored mode, so concurrent presses serialize: MENU on turns CUSTOMER DISPLAY off and vice
 * versa, pressing the active control again returns to Ads, and exactly zero or one control is ever on. A takeover in
 * progress is not touched (it is ephemeral and restores whatever mode is stored when it ends).
 */
class ToggleCustomerScreenMode
{
    public function __construct(private CustomerScreens $screens, private PosAccess $access) {}

    public function execute(User $user, Branch $branch, string $stationId, CustomerScreenMode $control): ?CustomerScreen
    {
        if ($control === CustomerScreenMode::Ads) {
            throw new InvalidArgumentException('Ads is the default when no control is on; it is not a control.');
        }

        return DB::transaction(function () use ($user, $branch, $stationId, $control): ?CustomerScreen {
            $this->access->authorize($user, $branch);
            $screen = CustomerScreen::query()
                ->where('branch_id', $branch->getKey())
                ->where('station_hash', $this->screens->stationHash($stationId))
                ->lockForUpdate()
                ->first();
            if ($screen === null) {
                return null;
            }
            $screen->update(['mode' => $screen->mode->toggled($control)]);
            CustomerScreenChanged::dispatch([$screen->channel_key], 'mode');

            return $screen;
        }, 3);
    }
}
