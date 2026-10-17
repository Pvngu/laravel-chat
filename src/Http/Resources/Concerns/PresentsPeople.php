<?php

namespace Pvngu\Chat\Http\Resources\Concerns;

use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Contracts\ChatUserProvider;

trait PresentsPeople
{
    /** A person as the chat shows them; a placeholder when their account no longer exists. */
    protected function person(?Model $user, int|string|null $key): array
    {
        return $user
            ? app(ChatUserProvider::class)->present($user)
            : ['id' => $this->missingPersonId($key), 'name' => '', 'email' => null, 'avatarUrl' => null];
    }

    protected function personId(?Model $user, int|string|null $key): string
    {
        return $user ? app(ChatUserProvider::class)->publicId($user) : $this->missingPersonId($key);
    }

    // Still their public id, never the database key (which an app may keep from the browser)
    private function missingPersonId(int|string|null $key): string
    {
        return $key === null || $key === '' ? '' : app(ChatUserProvider::class)->publicIdForKey($key);
    }
}
