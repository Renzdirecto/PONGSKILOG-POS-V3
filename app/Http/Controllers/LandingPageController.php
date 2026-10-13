<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\LandingTeamMember;
use App\Models\ShippySetting;
use App\Support\LandingLove;
use App\Support\LandingSeo;
use App\Support\ShippyKnowledge;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    public function __invoke(LandingLove $love): Response
    {
        return Inertia::render('welcome', [
            'reviews' => CustomerReview::query()->published()->orderByDesc('created_at')->orderByDesc('id')->limit(60)->get()->map->projection(),
            'reviewSummary' => CustomerReview::publishedSummary(),
            'reviewBranches' => Branch::query()->where('status', 'active')->orderBy('name')->get(['id', 'name', 'code']),
            'reviewNoticeVersion' => CustomerReview::NOTICE_VERSION,
            /** Website → Team decides who is shown; an edit is live on the next visit, with no deployment. */
            'team' => LandingTeamMember::query()->where('is_visible', true)->orderBy('position')->orderBy('id')->get()->map->card(),
            'loveTotal' => $love->total(),
            /** Shippy's six preset questions, or null while Website → Shippy has the chat switched off. */
            'shippy' => ShippySetting::current()->is_enabled ? ['presets' => ShippyKnowledge::PRESETS] : null,
            'seo' => LandingSeo::metadata(),
        ]);
    }
}
