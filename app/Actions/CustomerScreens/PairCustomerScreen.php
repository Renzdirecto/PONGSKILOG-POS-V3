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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Pairs the customer screen showing `$code` with this POS station at the cashier's selected Branch. Only an account
 * that may run this Branch's POS (`PosAccess`) can pair, the Branch comes from the server-side Branch context (never
 * from the request), and the code is one-time: it is cleared by the pairing that uses it. One station drives one
 * screen: a screen this station was paired with before is unpaired in the same transaction (unique Branch + station).
 */
class PairCustomerScreen
{
    public function __construct(
        private CustomerScreens $screens,
        private CustomerScreenLiveState $live,
        private PosAccess $access,
        private AuditRecorder $audit,
    ) {}

    public function execute(User $user, Branch $branch, string $stationId, mixed $code): CustomerScreen
    {
        $normalized = $this->screens->normalizeCode($code)
            ?? throw ValidationException::withMessages(['code' => 'Enter the 6-character code shown on the customer screen.']);

        /**
         * Two screens paired with the same station at the same instant meet at the unique (Branch, station) index; the
         * loser re-runs once and then sees (and releases) the winner, exactly like pairing a new screen later.
         */
        try {
            [$screen, $released] = $this->pair($user, $branch, $stationId, $normalized);
        } catch (UniqueConstraintViolationException) {
            [$screen, $released] = $this->pair($user, $branch, $stationId, $normalized);
        }

        foreach ([$screen, ...$released] as $changed) {
            $this->live->reset($changed);
        }

        return $screen;
    }

    /** @return array{0: CustomerScreen, 1: Collection<int, CustomerScreen>} */
    private function pair(User $user, Branch $branch, string $stationId, string $normalized): array
    {
        return DB::transaction(function () use ($user, $branch, $stationId, $normalized): array {
            $user = $this->access->authorize($user, $branch);
            $screen = CustomerScreen::query()
                ->where('pairing_code_hash', $this->screens->codeHash($normalized))
                ->where('pairing_code_expires_at', '>', now())
                ->lockForUpdate()
                ->first();
            if ($screen === null) {
                throw ValidationException::withMessages(['code' => 'That code is invalid or has expired. Check the code on the customer screen.']);
            }
            $stationHash = $this->screens->stationHash($stationId);
            $released = CustomerScreen::query()
                ->where('branch_id', $branch->getKey())
                ->where('station_hash', $stationHash)
                ->whereKeyNot($screen->getKey())
                ->lockForUpdate()
                ->get();
            foreach ($released as $previous) {
                $previous->update(UnpairCustomerScreen::UNPAIRED);
            }
            $screen->update([
                'branch_id' => $branch->getKey(),
                'station_hash' => $stationHash,
                'paired_by_user_id' => $user->getKey(),
                'paired_at' => now(),
                'mode' => CustomerScreenMode::Ads,
                'pairing_code_hash' => null,
                'pairing_code_expires_at' => null,
            ]);
            $this->audit->record(
                branch: $branch,
                actor: $user,
                module: 'settings',
                action: 'customer_screen.paired',
                auditableType: CustomerScreen::class,
                auditableId: $screen->id,
                metadata: ['replaced_previous_screen' => $released->isNotEmpty()],
            );
            CustomerScreenChanged::dispatch(
                array_values([$screen->channel_key, ...$released->pluck('channel_key')->all()]),
                'pairing',
            );

            return [$screen, $released];
        }, 3);
    }
}
