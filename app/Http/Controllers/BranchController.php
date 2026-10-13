<?php

namespace App\Http\Controllers;

use App\Actions\Audit\AuditRecorder;
use App\Enums\BranchStatus;
use App\Enums\StoreSessionStatus;
use App\Events\CustomerCatalogChanged;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use App\Models\User;
use App\Support\ActiveBranchContext;
use App\Support\ReceiptDocument;
use App\Support\ReceiptLayout;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    /**
     * Business-wide Settings manages every Branch. A Branch-scoped Settings role sees only its selected assigned
     * Branch ("Branch Settings — MAIN"), never the list of other Branches.
     */
    public function index(Request $request, ActiveBranchContext $context): Response|RedirectResponse
    {
        Gate::authorize('viewAny', Branch::class);
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $scopeBranch = $user->hasBusinessWideScope() ? null : $context->managementBranch($user);
        if ($scopeBranch === false) {
            return to_route('workspace');
        }

        $branches = Branch::query()
            ->when($scopeBranch !== null, fn (Builder $query) => $query->whereKey($scopeBranch?->id))
            ->select(['id', 'code', 'name', 'status', 'address', 'contact', 'kiosk_code', 'qr_ordering_enabled', 'facebook_url', 'website_url', 'receipt_name', 'receipt_address', 'receipt_contact', 'receipt_footer', 'receipt_show_logo', 'receipt_logo_path', 'receipt_layout', 'image_path'])
            ->withExists(['storeSessions as store_is_open' => fn (Builder $query) => $query->where('status', StoreSessionStatus::Open)])
            ->orderBy('name')->orderBy('code')->get();

        return Inertia::render('branches/index', ['branches' => $branches->map(function (Branch $branch): array {
            $url = route('kiosk.show', ['branch' => $branch->kiosk_code]);
            $writer = new Writer(new ImageRenderer(
                new RendererStyle(320), new SvgImageBackEnd,
            ));

            return [
                ...$branch->makeHidden('image_path')->toArray(),
                'receipt_logo_url' => ReceiptDocument::logoUrl($branch),
                'receipt_layout' => ReceiptLayout::normalize($branch->receipt_layout),
                'image_url' => BranchImageController::url($branch),
                'qr_url' => $url,
                'qr_image' => 'data:image/svg+xml;base64,'.base64_encode($writer->writeString($url)),
            ];
        }), 'scope' => [
            'mode' => $scopeBranch === null ? 'business' : 'branch',
            'can_create' => $user->can('create', Branch::class),
            'can_edit_identity' => $scopeBranch === null && $user->can('create', Branch::class),
            'branch' => $scopeBranch?->only(['id', 'name', 'code']),
        ]]);
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
            $validated = $request->validated();
            /**
             * A Branch that is not Active refuses every Store Session action (Close Store, settlement, expenses), so
             * leaving Active with an open session would strand its Pay Later orders and cash drawer. Close the Store first.
             */
            if (isset($validated['status']) && $validated['status'] !== BranchStatus::Active->value
                && $branch->storeSessions()->where('status', StoreSessionStatus::Open)->exists()) {
                throw ValidationException::withMessages(['status' => 'Close the Store at '.$branch->name.' before changing the Branch from Active.']);
            }
            $before = $this->auditSnapshot($branch);
            $branch->update($validated);
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
