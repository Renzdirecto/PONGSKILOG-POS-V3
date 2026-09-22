<?php

namespace App\Models;

use Database\Factories\VoidAuthorizationSettingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/** @property Carbon $configured_at */
#[Fillable(['scope', 'pin_hash', 'configured_by_user_id', 'configured_at'])]
class VoidAuthorizationSetting extends Model
{
    /** @use HasFactory<VoidAuthorizationSettingFactory> */
    use HasFactory;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['configured_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function configuredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'configured_by_user_id');
    }
}
