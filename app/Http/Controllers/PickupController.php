<?php

namespace App\Http\Controllers;

use App\Actions\Pickup\SavePickupSubscription;
use App\Http\Requests\StorePickupSubscriptionRequest;
use App\Models\OrderPickupToken;
use App\Support\PickupStatus;
use App\Support\PickupTokens;
use App\Support\PushNotifications;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public Takeout pickup page (Phase 19.6B). Possessing the token is the whole capability: read this order's pickup
 * status and opt in or out of its Ready notification. There is no login, no staff shared data, no order mutation and
 * no way to reach another order (lookup is by the token's hash only; a raw order id or a hash is never accepted).
 * Responses are private and never cached; the page's own link is kept out of Referer headers.
 */
class PickupController extends Controller
{
    public function __construct(private PickupTokens $tokens, private PickupStatus $status) {}

    /** An invalid or expired link still gets the branded page, with an explanation instead of a raw error. */
    public function show(Request $request, string $token): Response
    {
        $pickup = $this->tokens->find($token);
        $status = $pickup === null || $pickup->isExpired() ? null : $this->status->for($pickup);
        $problem = $status !== null ? null : ($pickup !== null && $pickup->isExpired() ? 'expired' : 'invalid');
        Inertia::flushShared();
        $response = Inertia::render('pickup', [
            'token' => $problem === null ? $token : null,
            'pickup' => $status,
            'problem' => $problem,
        ])->toResponse($request);
        if ($problem !== null) {
            $response->setStatusCode($problem === 'expired' ? 410 : 404);
        }

        return $this->private($response);
    }

    public function status(string $token): JsonResponse
    {
        [, $status] = $this->resolve($token);

        return $this->private(response()->json(['pickup' => $status]));
    }

    public function subscribe(StorePickupSubscriptionRequest $request, string $token, SavePickupSubscription $save): JsonResponse
    {
        [$pickup, $status] = $this->resolve($token);
        if (! PushNotifications::enabled()) {
            return $this->private(response()->json(['message' => 'Notifications are not available right now. This page still updates live.'], 503));
        }
        abort_unless(in_array($status['status'], ['preparing', 'ready'], true), 409, 'This order no longer needs notifications.');
        $save->execute($pickup, $request->subscription());

        return $this->private(response()->json(['subscribed' => true]));
    }

    public function unsubscribe(string $token, SavePickupSubscription $save): JsonResponse
    {
        [$pickup] = $this->resolve($token);
        $save->remove($pickup);

        return $this->private(response()->json(['subscribed' => false]));
    }

    /** Realtime for this page only: its own `pickup.{channel_key}` channel, nothing broader. */
    public function authorizeChannel(Request $request, string $token): JsonResponse
    {
        [$pickup] = $this->resolve($token);
        $data = $request->validate([
            'channel_name' => ['required', 'string', 'max:150'],
            'socket_id' => ['required', 'regex:/\A[0-9]+\.[0-9]+\z/'],
        ]);
        abort_unless($data['channel_name'] === 'private-'.$this->tokens->channelName($pickup), 403);
        $broadcaster = Broadcast::connection();
        abort_unless($broadcaster instanceof PusherBroadcaster, 503, 'Live updates are temporarily unavailable.');

        return $this->private(response()->json(json_decode($broadcaster->getPusher()->authorizeChannel($data['channel_name'], $data['socket_id']), true, flags: JSON_THROW_ON_ERROR)));
    }

    /**
     * @return array{0: OrderPickupToken, 1: non-empty-array<string, mixed>}
     */
    private function resolve(string $token): array
    {
        $pickup = $this->tokens->find($token);
        abort_if($pickup === null, 404, 'This pickup link is not valid.');
        abort_if($pickup->isExpired(), 410, 'This pickup link has expired.');
        $status = $this->status->for($pickup);
        abort_if($status === null, 404, 'This pickup link is not valid.');

        return [$pickup, $status];
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function private(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
