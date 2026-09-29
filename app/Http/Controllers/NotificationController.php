<?php

namespace App\Http\Controllers;

use App\Events\NotificationsChanged;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Inertia;
use Inertia\Response;

class NotificationController extends Controller
{
    /**
     * The viewer's own persisted in-app notifications, newest first, 20 per page. Nothing here is fabricated: zero
     * records show an empty state and a zero unread count.
     */
    public function index(Request $request): Response
    {
        $user = $this->viewer($request);
        $filter = $request->query('filter') === 'unread' ? 'unread' : 'all';

        return Inertia::render('super-admin/notifications', [
            'notifications' => $user->notifications()
                ->when($filter === 'unread', fn ($query) => $query->whereNull('read_at'))
                ->reorder()
                ->latest('created_at')
                ->latest('id')
                ->paginate(20)
                ->withQueryString()
                ->through(fn (DatabaseNotification $notification): array => $this->present($notification)),
            'unreadCount' => $user->unreadNotifications()->count(),
            'filter' => $filter,
        ]);
    }

    /** The cheap unread count the Control Center badge refetches after a realtime signal or a reconnect. */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json(['unread' => $this->viewer($request)->unreadNotifications()->count()])
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Mark one of the viewer's own notifications read. With `open`, continue to its same-app link.
     */
    public function read(Request $request, string $notification): RedirectResponse
    {
        $user = $this->viewer($request);
        $record = $user->notifications()->whereKey($notification)->firstOrFail();
        if ($record->read_at === null) {
            $record->markAsRead();
            NotificationsChanged::dispatch((int) $user->id);
        }
        $url = $record->data['url'] ?? null;

        return $request->boolean('open') && is_string($url) && str_starts_with($url, '/') && ! str_starts_with($url, '//') && ! str_contains($url, '\\')
            ? redirect($url)
            : back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $user = $this->viewer($request);
        $updated = $user->unreadNotifications()->update(['read_at' => now()]);
        if ($updated > 0) {
            NotificationsChanged::dispatch((int) $user->id);
        }
        Inertia::flash('toast', ['type' => 'success', 'message' => $updated > 0 ? 'All notifications marked as read.' : 'No unread notifications.']);

        return back();
    }

    private function viewer(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User && $user->is_active, 403);

        return $user;
    }

    /** @return array{id: string, category: string, title: string, body: string, url: string|null, read: bool, created_at: string|null} */
    private function present(DatabaseNotification $notification): array
    {
        /** @var array<string, mixed> $data */
        $data = $notification->data;

        return [
            'id' => (string) $notification->id,
            'category' => is_string($data['category'] ?? null) ? $data['category'] : 'general',
            'title' => is_string($data['title'] ?? null) ? $data['title'] : 'Notification',
            'body' => is_string($data['body'] ?? null) ? $data['body'] : '',
            'url' => is_string($data['url'] ?? null) ? $data['url'] : null,
            'read' => $notification->read_at !== null,
            'created_at' => $notification->created_at?->toIso8601String(),
        ];
    }
}
