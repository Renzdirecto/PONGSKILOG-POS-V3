<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\CustomerQrAccess;
use App\Support\CustomerQrProjection;
use App\Support\StoreState;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class CustomerQrController extends Controller
{
    public function __invoke(Request $request, Branch $branch, StoreState $storeState, CustomerQrAccess $access, CustomerQrProjection $projection): Response
    {
        $session = $access->start($request, $branch);
        $order = $session->activeOrder;

        $response = Inertia::render('qr/show', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'store' => ['status' => $storeState->customerAvailable($branch) ? 'open' : 'closed'],
            'catalog' => fn () => $projection->catalog($branch),
            'order' => fn () => $order === null ? null : $projection->order($order),
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
