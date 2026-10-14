<?php

namespace App\Models;

use Database\Factories\WebsiteEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One anonymous first-party event of the public landing page (v1.2.1 Website Analytics). It holds no IP address, user
 * agent, URL or account: only the event name, its Asia/Manila date and a keyed session hash that rotates daily.
 *
 * @property int $id
 * @property string $event
 * @property string|null $visitor
 * @property string $occurred_on
 * @property Carbon|null $created_at
 */
#[Fillable(['event', 'visitor', 'occurred_on', 'created_at'])]
class WebsiteEvent extends Model
{
    /** @use HasFactory<WebsiteEventFactory> */
    use HasFactory, MassPrunable;

    public const UPDATED_AT = null;

    /** Events are kept for one year, then removed by the daily `model:prune`. */
    public const RETENTION_DAYS = 365;

    /** @return Builder<WebsiteEvent> */
    public function prunable(): Builder
    {
        return self::query()->where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
