<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CustomerReview;
use App\Support\LandingSeo;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('welcome', [
            'reviews' => CustomerReview::query()->published()->orderByDesc('created_at')->orderByDesc('id')->limit(60)->get()->map->projection(),
            'reviewBranches' => Branch::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'reviewNoticeVersion' => CustomerReview::NOTICE_VERSION,
            'seo' => LandingSeo::metadata(),
        ]);
    }
}
