<?php

namespace App\Models;

use App\Enums\CustomerScreenMode;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One customer-facing display device (Phase 19.6A). The device proves itself with a random HttpOnly cookie whose
 * SHA-256 is `token_hash`; it is paired to one Branch POS station (`station_hash`, the SHA-256 of that station's local
 * installation id), never to the cashier account using the station. `mode` is the persistent selection; the live cart
 * and the order takeover are ephemeral cache state (`CustomerScreenLiveState`).
 *
 * @property string $id
 * @property string $token_hash
 * @property string $channel_key
 * @property string|null $pairing_code_hash
 * @property Carbon|null $pairing_code_expires_at
 * @property string|null $branch_id
 * @property string|null $station_hash
 * @property int|null $paired_by_user_id
 * @property Carbon|null $paired_at
 * @property CustomerScreenMode $mode
 * @property Carbon|null $last_seen_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Branch|null $branch
 */
#[Fillable(['token_hash', 'channel_key', 'pairing_code_hash', 'pairing_code_expires_at', 'branch_id', 'station_hash', 'paired_by_user_id', 'paired_at', 'mode', 'last_seen_at'])]
#[Hidden(['token_hash', 'channel_key', 'pairing_code_hash', 'station_hash'])]
class CustomerScreen extends Model
{
    use HasUuids, MassPrunable;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pairing_code_expires_at' => 'datetime',
            'paired_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'mode' => CustomerScreenMode::class,
        ];
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function isPaired(): bool
    {
        return $this->branch_id !== null && $this->station_hash !== null;
    }

    /**
     * Unpaired devices nobody has opened for a week (an abandoned browser or a crawler that asked for a code).
     *
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->whereNull('branch_id')->where('updated_at', '<', now()->subDays(7));
    }
}
