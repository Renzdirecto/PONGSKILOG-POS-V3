<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Audit\AuditRecorder;
use App\Events\UserContextChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Support\AccessRealtime;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\File;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The signed-in account's own profile (Phase 20): only what a staff member may choose for themselves — a Preferred
 * Name and a profile photo. Identity (full name, e-mail, Employee ID, Position, Role, Branches, status) is shown
 * read-only and stays in Staff administration.
 */
class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $user->loadMissing(['branches' => fn ($query) => $query->wherePivot('is_active', true)->orderBy('name')]);

        return Inertia::render('settings/profile', [
            'identity' => [
                'name' => $user->name,
                'email' => $user->email,
                'employee_id' => $user->employee_id,
                'position' => $user->position,
                'branches' => $user->branches->map(fn ($branch): string => $branch->name)->values()->all(),
            ],
            'preferredName' => $user->preferred_name,
        ]);
    }

    /**
     * Update the user's Preferred Name. The account's other open tabs show it without a manual refresh.
     */
    public function update(ProfileUpdateRequest $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $preferred = $request->validated('preferred_name');

        if ($preferred !== $user->preferred_name) {
            DB::transaction(function () use ($user, $preferred, $audit): void {
                $before = $user->preferred_name;
                $user->forceFill(['preferred_name' => $preferred])->save();
                $audit->record(
                    branch: null,
                    actor: $user,
                    module: 'account',
                    action: 'account.preferred_name_changed',
                    auditableType: User::class,
                    auditableId: (string) $user->id,
                    before: ['preferred_name' => $before],
                    after: ['preferred_name' => $preferred],
                );
                AccessRealtime::usersChanged((int) $user->id, UserContextChanged::IDENTITY);
                AccessRealtime::staffChanged(AccessRealtime::branchIdsOf([(int) $user->id]));
            });
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Replace the account's own profile photo (same rules and private disk as Staff administration).
     */
    public function updateAvatar(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        $request->validate(['avatar' => ['required', File::image(allowSvg: false)->types(['jpg', 'jpeg', 'png', 'webp'])->max('2mb')->dimensions(
            Rule::dimensions()->minWidth(64)->minHeight(64)->maxWidth(8000)->maxHeight(8000),
        )]]);
        $avatar = $request->file('avatar');
        abort_unless($avatar instanceof UploadedFile, 422);
        $disk = (string) config('filesystems.staff_avatars_disk', 'local');
        $stored = Storage::disk($disk)->putFileAs('staff-avatars/'.$user->id, $avatar, Str::uuid().'.'.($avatar->guessExtension() ?: 'jpg'));
        if ($stored === false) {
            throw ValidationException::withMessages(['avatar' => 'The profile photo could not be stored. Try again.']);
        }

        try {
            $this->replaceAvatar($user, $stored, $disk, $audit);
        } catch (\Throwable $exception) {
            Storage::disk($disk)->delete($stored);
            throw $exception;
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Remove the account's own profile photo.
     */
    public function destroyAvatar(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user instanceof User, 401);
        if ($user->avatar_path !== null) {
            $this->replaceAvatar($user, null, (string) config('filesystems.staff_avatars_disk', 'local'), $audit);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile photo removed.')]);

        return to_route('profile.edit');
    }

    /**
     * The signed-in account's own profile picture for its shell and sidebar, streamed from the private disk. The
     * storage path is never exposed.
     */
    public function avatar(Request $request): StreamedResponse
    {
        $user = $request->user();
        $disk = Storage::disk((string) config('filesystems.staff_avatars_disk', 'local'));
        abort_if($user === null || $user->avatar_path === null || ! $disk->exists($user->avatar_path), 404);

        return $disk->response($user->avatar_path, null, [
            'Cache-Control' => 'private, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function replaceAvatar(User $user, ?string $path, string $disk, AuditRecorder $audit): void
    {
        DB::transaction(function () use ($user, $path, $disk, $audit): void {
            $old = $user->avatar_path;
            $user->forceFill(['avatar_path' => $path])->save();
            $audit->record(
                branch: null,
                actor: $user,
                module: 'account',
                action: $path === null ? 'account.avatar_removed' : 'account.avatar_updated',
                auditableType: User::class,
                auditableId: (string) $user->id,
                before: ['has_profile_picture' => $old !== null],
                after: ['has_profile_picture' => $path !== null],
            );
            AccessRealtime::usersChanged((int) $user->id, UserContextChanged::IDENTITY);
            AccessRealtime::staffChanged(AccessRealtime::branchIdsOf([(int) $user->id]));
            if ($old !== null) {
                DB::afterCommit(fn () => Storage::disk($disk)->delete($old));
            }
        });
    }
}
