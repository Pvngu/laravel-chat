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
            : ['id' => (string) $key, 'name' => '', 'email' => null, 'avatarUrl' => null];
    }

    protected function personId(?Model $user, int|string|null $key): string
    {
        return $user ? app(ChatUserProvider::class)->publicId($user) : (string) $key;
    }
}
