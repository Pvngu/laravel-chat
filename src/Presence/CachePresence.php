<?php

namespace Pvngu\Chat\Presence;

use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Contracts\ChatPresence;
use Pvngu\Chat\Contracts\ChatUserProvider;

/**
 * Presence in the cache: per person, what each of their connections (browser tabs) last reported and when. A
 * connection counts for `presence.ttl` seconds (two minutes; vue-chat reports every minute), so a closed tab drops
 * out on its own.
 */
class CachePresence implements ChatPresence
{
    private const UNIDENTIFIED = 'default';

    public function __construct(
        private readonly CacheFactory $cache,
        private readonly ChatUserProvider $users,
    ) {}

    public function touch(Model $user, ?string $status = null, ?string $connection = null): bool
    {
        $key = $this->key($this->users->tenant($user), $this->users->publicId($user));
        $connections = $this->fresh($this->store()->get($key));
        $before = $this->overall($connections);

        if ($connection === null) {
            $connection = self::UNIDENTIFIED;
        } else {
            // Requests without a connection id come from a page before its socket connects (or from a client without
            // one): once that page reports with its id, the anonymous entry would only keep it online for too long
            unset($connections[self::UNIDENTIFIED]);
        }
        $connections[$connection] = [
            'status' => $status ?? ($connections[$connection]['status'] ?? self::ONLINE),
            'at' => now()->getTimestamp(),
        ];
        $this->store()->put($key, $connections, $this->ttl());

        return $this->overall($connections) !== $before;
    }

    public function statuses(Model $viewer, array $publicIds): array
    {
        if ($publicIds === []) {
            return [];
        }
        $tenant = $this->users->tenant($viewer);
        $stored = $this->store()->many(array_map(fn (string $id) => $this->key($tenant, $id), $publicIds));

        $statuses = [];
        foreach ($publicIds as $id) {
            $status = $this->overall($this->fresh($stored[$this->key($tenant, $id)] ?? null));
            if ($status !== null) {
                $statuses[$id] = $status;
            }
        }

        return $statuses;
    }

    /**
     * The connections heard from within the TTL.
     *
     * @return array<string, array{status: string, at: int}>
     */
    private function fresh(mixed $connections): array
    {
        $since = now()->getTimestamp() - $this->ttl();

        return array_filter(
            is_array($connections) ? $connections : [],
            fn (mixed $connection) => is_array($connection) && ($connection['at'] ?? 0) >= $since,
        );
    }

    /** @param  array<string, array{status: string, at: int}>  $connections */
    private function overall(array $connections): ?string
    {
        if ($connections === []) {
            return null;
        }

        return in_array(self::ONLINE, array_column($connections, 'status'), true) ? self::ONLINE : self::AWAY;
    }

    private function ttl(): int
    {
        return (int) config('laravel-chat.presence.ttl', 120);
    }

    private function store(): Repository
    {
        return $this->cache->store(config('laravel-chat.presence.store'));
    }

    private function key(string $tenant, string $publicId): string
    {
        return 'laravel-chat:presence:'.sha1($tenant).':'.$publicId;
    }
}
