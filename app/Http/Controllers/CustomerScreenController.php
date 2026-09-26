<?php

namespace App\Http\Controllers;

use App\Actions\CustomerScreens\UnpairCustomerScreen;
use App\Models\CustomerScreen;
use App\Support\CustomerMenu;
use App\Support\CustomerScreenMediaLibrary;
use App\Support\CustomerScreenProjection;
use App\Support\CustomerScreens;
use Illuminate\Broadcasting\Broadcasters\PusherBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The public customer-facing screen (Phase 19.6A): a kiosk page with no login, no staff navigation and no staff shared
 * props. The device is identified only by its own HttpOnly cookie; everything it may read is the allowlisted
 * projection of the Branch/station it is paired with. It has no endpoint that could add to a cart, change an order
 * or take a payment — the only writes are asking for its own pairing code and resetting its own pairing.
 */
class CustomerScreenController extends Controller
{
    public function __construct(private CustomerScreens $screens, private CustomerScreenProjection $projection) {}

    public function show(Request $request): Response
    {
        Inertia::flushShared();

        return $this->noStore(Inertia::render('customer-screen', [
            'screen' => $this->projection->for($this->touch($this->screens->resolve($request)), $request->getSchemeAndHttpHost()),
        ])->toResponse($request));
    }

    public function state(Request $request): JsonResponse
    {
        return $this->noStore(response()->json([
            'screen' => $this->projection->for($this->touch($this->screens->resolve($request)), $request->getSchemeAndHttpHost()),
        ]));
    }

    /**
     * A fresh one-time pairing code for this (unpaired) device, valid 5 minutes. Asking again replaces the previous
     * code. A paired device gets no code.
     */
    public function pairingCode(Request $request): JsonResponse
    {
        [$screen, $cookie] = $this->screens->resolveOrCreate($request);
        if ($screen->isPaired()) {
            return $this->noStore(response()->json(['code' => null, 'expires_at' => null]));
        }
        $code = null;
        for ($attempt = 0; $attempt < 5 && $code === null; $attempt++) {
            $candidate = $this->screens->newCode();
            $hash = $this->screens->codeHash($candidate);
            if (! CustomerScreen::query()->where('pairing_code_hash', $hash)->exists()) {
                $code = $candidate;
                $screen->update([
                    'pairing_code_hash' => $hash,
                    'pairing_code_expires_at' => now()->addSeconds(CustomerScreens::CODE_TTL_SECONDS),
                ]);
            }
        }
        abort_if($code === null, 503, 'A pairing code could not be created. Try again.');
        $response = response()->json([
            'code' => $code,
            'expires_at' => $screen->pairing_code_expires_at?->toIso8601String(),
            'screen' => $this->projection->for($screen, $request->getSchemeAndHttpHost()),
        ]);

        return $this->noStore($cookie === null ? $response : $response->withCookie($cookie));
    }

    public function menu(Request $request, CustomerMenu $menu): JsonResponse
    {
        $screen = $this->pairedScreen($request);

        return $this->noStore(response()->json(['menu' => $menu->for($screen->branch ?? abort(404))]));
    }

    public function media(Request $request, CustomerScreenMediaLibrary $library): JsonResponse
    {
        $screen = $this->pairedScreen($request);

        return $this->noStore(response()->json(['media' => $library->playlist($screen->branch ?? abort(404))]));
    }

    /** The screen's hidden staff reset (a long press): unpairs this device only, e.g. when its station was lost. */
    public function reset(Request $request, UnpairCustomerScreen $unpair): JsonResponse
    {
        $screen = $this->screens->resolve($request) ?? abort(404);
        $unpair->fromScreen($screen);

        return $this->noStore(response()->json(['reset' => true]));
    }

    /**
     * Realtime for this device only: its own screen channel and, once paired, its Branch's Customer QR catalog signal
     * (Menu refresh) and Customer Display signal (order-number board). All three carry ids/time only.
     */
    public function authorizeChannel(Request $request): JsonResponse
    {
        $screen = $this->screens->resolve($request) ?? abort(403);
        $data = $request->validate([
            'channel_name' => ['required', 'string', 'max:150'],
            'socket_id' => ['required', 'regex:/\A[0-9]+\.[0-9]+\z/'],
        ]);
        $allowed = ['private-'.$this->screens->channelName($screen)];
        if ($screen->isPaired()) {
            $allowed[] = 'private-qr-catalog.'.$screen->branch_id;
            $allowed[] = 'private-branch.'.$screen->branch_id.'.customer-display';
        }
        abort_unless(in_array($data['channel_name'], $allowed, true), 403);
        $broadcaster = Broadcast::connection();
        abort_unless($broadcaster instanceof PusherBroadcaster, 503, 'Live updates are temporarily unavailable.');

        return $this->noStore(response()->json(json_decode($broadcaster->getPusher()->authorizeChannel($data['channel_name'], $data['socket_id']), true, flags: JSON_THROW_ON_ERROR)));
    }

    private function pairedScreen(Request $request): CustomerScreen
    {
        $screen = $this->screens->resolve($request);
        abort_unless($screen !== null && $screen->isPaired(), 404);

        return $screen;
    }

    /** Records that the screen is alive (at most once a minute) for the POS pairing status. */
    private function touch(?CustomerScreen $screen): ?CustomerScreen
    {
        if ($screen !== null && ($screen->last_seen_at === null || $screen->last_seen_at->lt(now()->subMinute()))) {
            $screen->forceFill(['last_seen_at' => now()])->saveQuietly();
        }

        return $screen;
    }

    /**
     * @template T of Response
     *
     * @param  T  $response
     * @return T
     */
    private function noStore(Response $response): Response
    {
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
