<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CustomerQrSession;
use App\Support\CustomerQrAccess;
use App\Support\CustomerQrProjection;
use App\Support\StoreState;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CustomerQrController extends Controller
{
    public function legacy(Request $request, Branch $branch, CustomerQrAccess $access): Response
    {
        $access->start($request, $branch);

        return redirect()->route('kiosk.show', ['branch' => $branch->kiosk_code]);
    }

    public function __invoke(Request $request, Branch $branch, StoreState $storeState, CustomerQrAccess $access, CustomerQrProjection $projection): Response
    {
        $session = $access->start($request, $branch);
        if (! $request->header('X-Inertia')) {
            DB::transaction(function () use ($session, $branch): void {
                CustomerQrSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
                $visits = DB::table('customer_qr_visits');
                if (! (clone $visits)->where('customer_qr_session_id', $session->id)->where('branch_id', $branch->id)->where('visited_at', '>', now()->subMinutes(2))->exists()) {
                    $visits->insert(['branch_id' => $branch->id, 'customer_qr_session_id' => $session->id, 'visited_at' => now()]);
                }
            });
        }
        $order = $session->activeOrder;

        $response = Inertia::render('qr/show', [
            'branch' => $branch->only(['id', 'name', 'code', 'facebook_url', 'website_url']),
            'store' => ['status' => $storeState->customerAvailable($branch) ? 'open' : 'closed'],
            'catalog' => fn () => $projection->catalog($branch),
            'order' => fn () => $order === null ? null : $projection->order($order),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
