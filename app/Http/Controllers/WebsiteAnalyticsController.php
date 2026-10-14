<?php

namespace App\Http\Controllers;

use App\Http\Requests\WebsiteAnalyticsRequest;
use App\Models\Branch;
use App\Models\CustomerReview;
use App\Models\LandingTeamMember;
use App\Models\ShippyDocument;
use App\Models\ShippyFaq;
use App\Models\ShippySetting;
use App\Models\User;
use App\Support\ReportPeriod;
use App\Support\ShippyAssistant;
use App\Support\ShippyUsage;
use App\Support\WebsiteAnalytics;
use Inertia\Inertia;
use Inertia\Response;

class WebsiteAnalyticsController extends Controller
{
    /**
     * The Owner's Website workspace (v1.3.0): Analytics (read-only counters of the public landing page), Reviews (the
     * moderation moved here from Settings), Team (the landing page's team) and Shippy (the chat's switch, knowledge and usage).
     * The website belongs to the whole business, never to one Branch, so only business-wide accounts are authorized
     * (see the request) and the selected Branch narrows nothing. A tab the account may not use gets no data.
     */
    public function __invoke(WebsiteAnalyticsRequest $request, WebsiteAnalytics $analytics, ShippyUsage $usage): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $period = ReportPeriod::fromFilters([
            'date' => $request->validated('period') ?? 'today',
            'from' => $request->validated('from'),
            'to' => $request->validated('to'),
        ]);
        $can = [
            'analytics' => $user->hasPermission('reports.view'),
            'reviews' => $user->can('viewAny', CustomerReview::class),
            'team' => $user->can('viewAny', LandingTeamMember::class),
            'shippy' => $user->can('viewAny', ShippySetting::class),
        ];

        return Inertia::render('workspaces/website-analytics', [
            'can' => $can,
            'report' => fn (): ?array => $can['analytics'] ? $analytics->report($period) : null,
            'reviewBranches' => fn (): array => $can['reviews'] ? Branch::query()->orderBy('name')->orderBy('code')->get(['id', 'name', 'code'])->all() : [],
            'team' => fn (): array => $can['team'] ? LandingTeamMember::query()->orderBy('position')->orderBy('id')->get()->map->card()->all() : [],
            /** Whether a key is configured is shown; the key itself never leaves the environment. */
            'shippy' => fn (): ?array => $can['shippy'] ? [
                'enabled' => ShippySetting::current()->is_enabled,
                'configured' => filled(config('services.gemini.key')),
                'model' => (string) config('services.gemini.model'),
                'usage' => $usage->summary(),
                'faqs' => ShippyFaq::query()->orderBy('category')->orderBy('id')->get()->map->row()->all(),
                /** A document's name, size and state: its text stays on the server. */
                'documents' => ShippyDocument::query()->orderBy('filename')->get(['id', 'filename', 'bytes', 'sections', 'is_enabled', 'updated_at'])->map->row()->all(),
                'limits' => [
                    'faqs' => ShippyFaq::MAX_FAQS, 'alternatives' => ShippyFaq::MAX_ALTERNATIVES, 'category' => ShippyFaq::MAX_CATEGORY,
                    'question' => ShippyAssistant::MAX_MESSAGE, 'answer' => ShippyAssistant::MAX_REPLY,
                    'documents' => ShippyDocument::MAX_DOCUMENTS, 'document_kilobytes' => ShippyDocument::MAX_KILOBYTES,
                ],
            ] : null,
        ]);
    }
}
