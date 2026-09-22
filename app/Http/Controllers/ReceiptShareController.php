<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\CustomerQrProjection;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

class ReceiptShareController extends Controller
{
    public function store(Request $request, Order $order, ActiveBranchContext $context, CustomerQrProjection $projection): JsonResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $branch = $context->current($user);
        abort_if($branch === null, 403);
        abort_unless($order->branch_id === $branch->id, 404);
        $receipt = $this->receipt($order, $projection);
        $path = URL::temporarySignedRoute('receipt.show', CarbonImmutable::parse($receipt['receipt_expires_at']), ['order' => $order->id], absolute: false);
        $writer = new Writer(new ImageRenderer(new RendererStyle(320), new SvgImageBackEnd));

        return response()->json([
            'url' => $path,
            'expires_at' => $receipt['receipt_expires_at'],
            'qr_image' => 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($request->getSchemeAndHttpHost().$path)),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function show(Request $request, Order $order, CustomerQrProjection $projection): Response
    {
        abort_unless(URL::hasCorrectSignature($request, absolute: false), 403);
        abort_unless($request->query('expires') && URL::signatureHasNotExpired($request), 410, 'Digital receipt has expired');
        $receipt = $this->receipt($order, $projection);
        Inertia::flushShared();
        $response = $request->expectsJson()
            ? response()->json(['receipt' => $receipt])
            : Inertia::render('public-receipt', ['receipt' => $receipt])->toResponse($request);
        $response->headers->set('Cache-Control', 'private, no-store');
        $response->headers->set('Referrer-Policy', 'no-referrer');

        return $response;
    }

    /** @return array<string, mixed> */
    private function receipt(Order $order, CustomerQrProjection $projection): array
    {
        abort_unless(filled($order->order_number) && filled($order->reference_number), 404);

        return Arr::only($projection->receipt($order), [
            'order_number', 'reference_number', 'paid_at', 'receipt_expires_at', 'order_type',
            'customer_label', 'table_name', 'items', 'subtotal', 'total', 'branch', 'payments',
        ]);
    }
}
