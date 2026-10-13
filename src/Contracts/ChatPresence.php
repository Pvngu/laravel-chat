<?php

namespace Pvngu\Chat\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Who is online, for `@here` mentions. The default (Pvngu\Chat\Presence\CachePresence) counts anyone who used the chat
 * in the last couple of minutes; vue-chat sends a heartbeat while the page is open. Bind your own in
 * config('laravel-chat.presence.driver') if your app already tracks presence.
 */
interface ChatPresence
{
    /** The person is around: called on every chat API request and heartbeat. */
    public function touch(Model $user): void;

    /**
     * The ones online now, out of these users.
     *
     * @param  array<int, int|string>  $userKeys  user model keys
     * @return array<int, int|string>
     */
    public function online(array $userKeys): array;
}
