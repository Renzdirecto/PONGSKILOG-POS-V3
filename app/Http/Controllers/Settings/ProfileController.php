<?php

namespace App\Http\Controllers\Settings;

use App\Events\UserContextChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Support\AccessRealtime;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'emailVerified' => $request->user()?->email_verified_at !== null,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();
        /** The account's other open tabs show the new name without a manual refresh. */
        AccessRealtime::usersChanged((int) $request->user()->id, UserContextChanged::IDENTITY);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * The signed-in account's own profile picture (set by Staff administration) for its shell and sidebar, streamed
     * from the private disk. The storage path is never exposed.
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
}
