<?php

namespace App\Models;

use App\Enums\PermissionOverrideEffect;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $user_id
 * @property int $permission_id
 * @property PermissionOverrideEffect $effect
 */
#[Fillable(['user_id', 'permission_id', 'effect'])]
class UserPermissionOverride extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['effect' => PermissionOverrideEffect::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Permission, $this> */
    public function permission(): BelongsTo
    {
        return $this->belongsTo(Permission::class);
    }
}
