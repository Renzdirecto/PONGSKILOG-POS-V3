<?php

namespace App\Http\Requests;

/**
 * A customer's browser `PushSubscription.toJSON()` for one pickup page. The same endpoint allowlist (SSRF) and key
 * checks as staff subscriptions; the pickup token in the URL, not an account, is the capability.
 */
class StorePickupSubscriptionRequest extends StorePushSubscriptionRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
