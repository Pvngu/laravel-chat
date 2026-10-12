<?php

namespace Pvngu\Chat\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Users\DefaultChatUserProvider;

// Like an app that exposes hashed ids (Hashids, xid…) instead of database keys
class HashedIdUserProvider extends DefaultChatUserProvider
{
    public function publicId(Model $user): string
    {
        return 'u_'.base_convert((string) ($user->getKey() * 7919), 10, 36);
    }

    public function find(string $publicId): ?Model
    {
        if (! preg_match('/^u_([0-9a-z]+)$/', $publicId, $match)) {
            return null;
        }

        $number = (int) base_convert($match[1], 36, 10);

        return $number % 7919 === 0 ? User::query()->find(intdiv($number, 7919)) : null;
    }
}
