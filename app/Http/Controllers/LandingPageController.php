<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\LandingTeamMember;
use App\Models\ShippySetting;
use App\Support\LandingLove;
use App\Support\LandingSeo;
use App\Support\ShippyKnowledge;
use App\Support\StoreState;
use Inertia\Inertia;
use Inertia\Response;

class LandingPageController extends Controller
{
    public function __invoke(LandingLove $love, StoreState $store): Response
    {
        $branches = Branch::query()->where('status', 'active')->orderBy('name')->get();

        return Inertia::render('welcome', [
            'reviews' => CustomerReview::query()->published()->orderByDesc('created_at')->orderByDesc('id')->limit(60)->get()->map->projection(),
            'reviewSummary' => CustomerReview::publishedSummary(),
            'reviewBranches' => $branches->map->only(['id', 'name', 'code'])->values(),
            'reviewNoticeVersion' => CustomerReview::NOTICE_VERSION,
            /** Website → Team decides who is shown; an edit is live on the next visit, with no deployment. */
            'team' => LandingTeamMember::query()->where('is_visible', true)->orderBy('position')->orderBy('id')->get()->map->card(),
            'loveTotal' => $love->total(),
            /**
             * Online kiosk (Landing V7): each active Branch's public Customer QR ordering page and whether it takes
             * orders right now. The link always works; the kiosk page itself decides what may be ordered.
             */
            'kiosk' => $branches->filter(fn (Branch $branch): bool => filled($branch->kiosk_code))->map(fn (Branch $branch): array => [
                'name' => $branch->name,
                'address' => $branch->address,
                'url' => route('kiosk.show', ['branch' => $branch->kiosk_code], false),
                'open' => $store->customerAvailable($branch),
            ])->values(),
            /** Shippy's six preset questions, or null while Website → Shippy has the chat switched off. */
            'shippy' => ShippySetting::current()->is_enabled ? ['presets' => ShippyKnowledge::PRESETS] : null,
            'seo' => LandingSeo::metadata(),
        ]);
    }
}
