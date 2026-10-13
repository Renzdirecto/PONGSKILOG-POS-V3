<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * One of the six people on the landing page's Meet the Team rail (v1.3.0), edited in Website → Team. The six rows are
 * created by the migration and never added or deleted: a card is filled in, cleared or hidden. An empty name, bio or
 * photo shows as a marked placeholder on the landing page, so nothing about a person is ever made up.
 *
 * @property int $id
 * @property int $position
 * @property string $role_label
 * @property string $role_title
 * @property string|null $name
 * @property string|null $bio
 * @property string|null $image_path
 * @property bool $is_visible
 */
#[Fillable(['role_label', 'role_title', 'name', 'bio', 'is_visible'])]
class LandingTeamMember extends Model
{
    /** Uploaded portraits live under this prefix on object storage; any other path is an image bundled in `public/`. */
    public const UPLOADS = 'landing-team/';

    protected $hidden = ['image_path'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['position' => 'integer', 'is_visible' => 'boolean'];
    }

    public function hasUploadedPhoto(): bool
    {
        return $this->image_path !== null && str_starts_with($this->image_path, self::UPLOADS);
    }

    /** The portrait's URL, or null. An upload gets a new path each time, so its versioned URL may be cached. */
    public function photoUrl(): ?string
    {
        if ($this->image_path === null) {
            return null;
        }

        return $this->hasUploadedPhoto()
            ? route('landing-team.photo', $this, false).'?v='.md5($this->image_path)
            : '/'.$this->image_path;
    }

    /**
     * The card the landing page and Website → Team render. The storage path never leaves the server.
     *
     * @return array{id: int, code: string, title: string, name: string|null, bio: string|null, img: string|null, visible: bool}
     */
    public function card(): array
    {
        return [
            'id' => $this->id, 'code' => $this->role_label, 'title' => $this->role_title, 'name' => $this->name,
            'bio' => $this->bio, 'img' => $this->photoUrl(), 'visible' => $this->is_visible,
        ];
    }
}
