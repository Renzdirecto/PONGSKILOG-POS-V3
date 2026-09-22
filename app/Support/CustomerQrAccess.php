<?php

namespace App\Support;

use App\Models\Branch;
use App\Models\CustomerQrSession;
use App\Models\Order;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;

class CustomerQrAccess
{
    /** @return non-empty-string */
    public function cookieName(Branch $branch): string
    {
        return 'customer_qr_'.$branch->id;
    }

    public function resolve(Request $request, Branch $branch): ?CustomerQrSession
    {
        $token = $request->cookie($this->cookieName($branch));
        if (! is_string($token) || preg_match('/\A[a-f0-9]{64}\z/', $token) !== 1) {
            return null;
        }

        return CustomerQrSession::query()->where('branch_id', $branch->id)
            ->where('token_hash', hash('sha256', $token))->where('expires_at', '>', now())->first();
    }

    public function start(Request $request, Branch $branch): CustomerQrSession
    {
        if ($session = $this->resolve($request, $branch)) {
            $token = $request->cookie($this->cookieName($branch));
            if (! is_string($token)) {
                abort(419);
            }
            Cookie::queue(cookie($this->cookieName($branch), $token, 60 * 24 * 7, '/', null, $request->isSecure(), true, false, 'lax'));

            return $session;
        }
        $token = bin2hex(random_bytes(32));
        $session = CustomerQrSession::query()->create([
            'branch_id' => $branch->id, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);
        Cookie::queue(cookie($this->cookieName($branch), $token, 60 * 24 * 7,
            '/', null, $request->isSecure(), true, false, 'lax'));

        return $session;
    }

    public function requireSession(Request $request, Branch $branch): CustomerQrSession
    {
        return $this->resolve($request, $branch) ?? abort(419, 'Your ordering session expired. Refresh the menu to continue.');
    }

    public function order(CustomerQrSession $session, string $trackingId): Order
    {
        abort_unless(preg_match('/\A[a-f0-9]{64}\z/', $trackingId) === 1, 404);

        return Order::query()->where('customer_qr_session_id', $session->id)
            ->where('branch_id', $session->branch_id)->where('public_tracking_id', $trackingId)->firstOrFail();
    }
}
