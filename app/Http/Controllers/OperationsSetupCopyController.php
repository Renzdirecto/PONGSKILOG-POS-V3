<?php

namespace App\Http\Controllers;

use App\Actions\Operations\CopyOperationsSetup;
use App\Models\Branch;
use App\Models\User;
use App\Support\OperationsAccess;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Operations › Copy setup from another Branch. The destination is always the selected Branch from the global Branch
 * context (never a browser-supplied id); the source is authorized by CopyOperationsSetup. Configuration only: stock,
 * movements, purchases and sales are never copied.
 */
class OperationsSetupCopyController extends Controller
{
    /** The dry-run review of the copy (nothing is written). */
    public function preview(Request $request, OperationsAccess $access, CopyOperationsSetup $copy): JsonResponse
    {
        $data = $this->validated($request);
        $user = $this->actor($request, $access);
        $destination = $access->configurationBranch($user);
        $source = $this->source($data['source_branch_id']);

        return response()->json([
            'source' => $source->only(['id', 'name', 'code']),
            'destination' => $destination->only(['id', 'name', 'code']),
            'result' => $copy->preview($user, $source, $destination, $data['sections'], (bool) $data['replace']),
        ])->header('Cache-Control', 'no-store');
    }

    public function store(Request $request, OperationsAccess $access, CopyOperationsSetup $copy): RedirectResponse
    {
        $data = $this->validated($request);
        $user = $this->actor($request, $access);
        $destination = $access->configurationBranch($user);
        $result = $copy->execute($user, $this->source($data['source_branch_id']), $destination, $data['sections'], (bool) $data['replace']);
        $parts = array_filter([
            $result['plans']['new'] + $result['plans']['replaced'] > 0 ? ($result['plans']['new'] + $result['plans']['replaced']).' plans' : null,
            $result['ingredients']['new'] + $result['ingredients']['replaced'] > 0 ? ($result['ingredients']['new'] + $result['ingredients']['replaced']).' ingredients' : null,
            $result['recipes']['products'] > 0 ? $result['recipes']['products'].' products configured' : null,
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => ($parts === [] ? 'Nothing new to copy: '.$destination->code.' already has this setup' : 'Copied to '.$destination->code.': '.implode(', ', $parts))
            .($result['skipped'] === [] ? '' : ' · '.count($result['skipped']).' skipped')
            .'. Stock and history were not copied.']);

        return back();
    }

    /** @return array{source_branch_id: string, sections: list<string>, replace: bool|string|int} */
    private function validated(Request $request): array
    {
        /** @var array{source_branch_id: string, sections: list<string>, replace: bool|string|int} */
        return $request->validate([
            'source_branch_id' => ['required', 'uuid'],
            'sections' => ['required', 'array', 'min:1', 'max:3'],
            'sections.*' => ['required', 'string', 'distinct', Rule::in(CopyOperationsSetup::SECTIONS)],
            'replace' => ['required', 'boolean'],
        ]);
    }

    private function actor(Request $request, OperationsAccess $access): User
    {
        return $access->authorize($request->user());
    }

    /** An unknown source is reported like an unauthorized one, so ids of other Branches cannot be probed. */
    private function source(string $id): Branch
    {
        $branch = Branch::query()->find($id);
        abort_if($branch === null, 403);

        return $branch;
    }
}
