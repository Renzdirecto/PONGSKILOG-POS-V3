<?php

namespace App\Enums;

/**
 * An explicit per-user permission exception. INHERIT is the absence of an override row, never a stored value.
 */
enum PermissionOverrideEffect: string
{
    case Allow = 'allow';
    case Deny = 'deny';
}
