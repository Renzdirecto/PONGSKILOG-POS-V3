<?php

namespace App\Actions\CustomerScreens;

use App\Actions\Audit\AuditRecorder;
use App\Enums\CustomerScreenMode;
use App\Events\CustomerScreenChanged;
use App\Models\Branch;
use App\Models\CustomerScreen;
use App\Models\User;
use App\Support\CustomerScreenLiveState;
use App\Support\CustomerScreens;
use App\Support\PosAccess;
use Illuminate\Support\Facades\DB;

/**
 * Ends a pairing: from the POS station that owns it (authorized through `PosAccess` at the selected Branch), or from
 * the screen device itself (its hidden reset, for a station whose browser storage was lost). The screen returns to
 * its pairing code and nothing of the previous station's cart or takeover remains.
 */
class UnpairCustomerScreen
{
    /** @var array<string, mixed> */
    public const UNPAIRED = [
        'branch_id' => null,
        'station_hash' => null,
        'paired_by_user_id' => null,
        'paired_at' => null,
        'mode' => CustomerScreenMode::Ads,
    ];

    public function __construct(
        private CustomerScreens $screens,
        private CustomerScreenLiveState $live,
        private PosAccess $access,
        private AuditRecorder $audit,
    ) {}

    /** @return bool whether a pairing existed */
    public function fromStation(User $user, Branch $branch, string $stationId): bool
    {
        $screen = DB::transaction(function () use ($user, $branch, $stationId): ?CustomerScreen {
            $user = $this->access->authorize($user, $branch);
            $screen = CustomerScreen::query()
                ->where('branch_id', $branch->getKey())
                ->where('station_hash', $this->screens->stationHash($stationId))
                ->lockForUpdate()
                ->first();
            if ($screen === null) {
                return null;
            }
            $screen->update(self::UNPAIRED);
            $this->audit->record(
                branch: $branch,
                actor: $user,
                module: 'settings',
                action: 'customer_screen.unpaired',
                auditableType: CustomerScreen::class,
                auditableId: $screen->id,
            );
            CustomerScreenChanged::dispatch([$screen->channel_key], 'pairing');

            return $screen;
        }, 3);

        if ($screen !== null) {
            $this->live->reset($screen);
        }

        return $screen !== null;
    }

    public function fromScreen(CustomerScreen $screen): void
    {
        DB::transaction(function () use ($screen): void {
            $locked = CustomerScreen::query()->whereKey($screen->getKey())->lockForUpdate()->firstOrFail();
            $branch = $locked->branch;
            if ($branch === null) {
                return;
            }
            $locked->update(self::UNPAIRED);
            $this->audit->record(
                branch: $branch,
                actor: null,
                module: 'settings',
                action: 'customer_screen.reset_on_screen',
                auditableType: CustomerScreen::class,
                auditableId: $locked->id,
            );
            CustomerScreenChanged::dispatch([$locked->channel_key], 'pairing');
        }, 3);
        $this->live->reset($screen);
    }
}
