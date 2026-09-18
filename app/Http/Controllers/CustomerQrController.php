<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Support\StoreState;
use Inertia\Inertia;
use Inertia\Response;

class CustomerQrController extends Controller
{
    public function __invoke(Branch $branch, StoreState $storeState): Response
    {
        return Inertia::render('qr/show', [
            'branch' => $branch->only(['id', 'name', 'code']),
            'store' => ['status' => $storeState->customerAvailable($branch) ? 'open' : 'closed'],
        ]);
    }
}
