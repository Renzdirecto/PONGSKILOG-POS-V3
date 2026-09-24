<?php

namespace App\Notifications;

use Illuminate\Notifications\Notification;

/**
 * One persisted in-app Control Center notification. It stores only a category, a readable title and summary, and a
 * server-generated relative link; never credentials, audit before/after payloads or exact money values.
 */
class AdminAlert extends Notification
{
    public const CATEGORIES = ['access', 'staff', 'stock'];

    public function __construct(
        public string $category,
        public string $title,
        public string $body,
        public ?string $url = null,
    ) {
        if (! in_array($category, self::CATEGORIES, true)) {
            throw new \InvalidArgumentException('Unknown notification category.');
        }
        if ($url !== null && (! str_starts_with($url, '/') || str_starts_with($url, '//') || str_contains($url, '\\'))) {
            throw new \InvalidArgumentException('Notification links must be same-app relative paths.');
        }
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function databaseType(object $notifiable): string
    {
        return 'admin.'.$this->category;
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array{category: string, title: string, body: string, url: string|null}
     */
    public function toArray(object $notifiable): array
    {
        return [
            'category' => $this->category,
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
        ];
    }
}
