<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerCatalogChanged;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\User;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->select(['id', 'code', 'name', 'status', 'address', 'contact', 'kiosk_code', 'qr_ordering_enabled', 'facebook_url', 'website_url', 'receipt_name', 'receipt_address', 'receipt_contact', 'receipt_footer', 'receipt_show_logo', 'receipt_logo_path'])
            ->withExists(['storeSessions as store_is_open' => fn (Builder $query) => $query->where('status', StoreSessionStatus::Open)])
            ->orderBy('name')->orderBy('code')->get();

        return Inertia::render('branches/index', ['branches' => $branches->map(function (Branch $branch): array {
            $url = route('kiosk.show', ['branch' => $branch->kiosk_code]);
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(320), new SvgImageBackEnd,
            ));

            return [...$branch->toArray(), 'receipt_logo_url' => $branch->receipt_logo_path ? route('branches.receipt-logo', $branch, false).'?v='.md5($branch->receipt_logo_path) : '/images/branding/logo.png', 'qr_url' => $url, 'qr_image' => 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url))];
        })]);
    }

    public function store(StoreBranchRequest $request, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $audit): void {
            $actor = $request->user();
            abort_unless($actor instanceof User, 401);
            $branch = Branch::query()->create($request->validated());
            $audit->record(
                branch: $branch,
                actor: $actor,
                module: 'branches',
                action: 'branch.created',
                auditableType: Branch::class,
                auditableId: $branch->id,
                after: $this->auditSnapshot($branch),
            );
        });

        return to_route('branches.index');
    }

    public function update(UpdateBranchRequest $request, Branch $branch, AuditRecorder $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $branch, $audit): void {
            $actor = $request->user();
            abort_unless($actor instanceof User, 401);
            $branch = Branch::query()->whereKey($branch->id)->lockForUpdate()->firstOrFail();
            $before = $this->auditSnapshot($branch);
            $branch->update($request->validated());
            $audit->record(
                branch: $branch,
                actor: $actor,
                module: 'branches',
                action: 'branch.updated',
                auditableType: Branch::class,
                auditableId: $branch->id,
                before: $before,
                after: $this->auditSnapshot($branch),
            );
            CustomerCatalogChanged::dispatch($branch->id);
        });

        return to_route('branches.index');
    }

    /** @return array<string, mixed> */
    private function auditSnapshot(Branch $branch): array
    {
        return $branch->only([
            'code', 'name', 'status', 'address', 'contact', 'kiosk_code',
            'qr_ordering_enabled', 'facebook_url', 'website_url',
        ]);
    }
}
