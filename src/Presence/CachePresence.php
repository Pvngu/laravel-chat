<?php

namespace Pvngu\Chat\Presence;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Contracts\ChatPresence;

// Online means a chat request (or heartbeat) within the last `presence.ttl` seconds, remembered in the cache
class CachePresence implements ChatPresence
{
    public function __construct(private readonly CacheFactory $cache) {}

    public function touch(Model $user): void
    {
        $this->store()->put($this->key($user->getKey()), true, (int) config('laravel-chat.presence.ttl', 120));
    }

    public function online(array $userKeys): array
    {
        if ($userKeys === []) {
            return [];
        }
        $seen = $this->store()->many(array_map(fn (int|string $key) => $this->key($key), $userKeys));

        return array_values(array_filter($userKeys, fn (int|string $key) => ($seen[$this->key($key)] ?? null) !== null));
    }

    private function store(): Repository
    {
        return $this->cache->store(config('laravel-chat.presence.store'));
    }

    private function key(int|string $userKey): string
    {
        return "laravel-chat:online:$userKey";
    }
}
