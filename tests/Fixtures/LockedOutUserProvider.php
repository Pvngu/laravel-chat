<?php

namespace Pvngu\Chat\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Users\DefaultChatUserProvider;

// Like an app whose provider only finds people who may still chat (an account disabled, or one that lost access)
class LockedOutUserProvider extends DefaultChatUserProvider
{
    /** @var array<int, string> Public ids find() no longer returns */
    public static array $lockedOut = [];

    public function find(string $publicId): ?Model
    {
        return in_array($publicId, self::$lockedOut, true) ? null : parent::find($publicId);
    }
}
