<?php

namespace App\Http\Controllers;

use App\Enums\StoreSessionStatus;
use App\Http\Requests\StoreBranchRequest;
use App\Http\Requests\UpdateBranchRequest;
use App\Models\Branch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class BranchController extends Controller
{
    public function index(): Response
    {
        Gate::authorize('viewAny', Branch::class);

        $branches = Branch::query()
            ->select(['id', 'code', 'name', 'status', 'address', 'contact'])
            ->withExists(['storeSessions as store_is_open' => fn (Builder $query) => $query->where('status', StoreSessionStatus::Open)])
            ->orderBy('name')->orderBy('code')->get();

        return Inertia::render('branches/index', ['branches' => $branches]);
    }

    public function store(StoreBranchRequest $request): RedirectResponse
    {
        Branch::query()->create($request->validated());

        return to_route('branches.index');
    }

    public function update(UpdateBranchRequest $request, Branch $branch): RedirectResponse
    {
        $branch->update($request->validated());

        return to_route('branches.index');
    }
}
