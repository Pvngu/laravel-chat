<?php

namespace Pvngu\Chat\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Who is around: online (active), away (idle or in a hidden tab), or neither (offline). It colours the status dots in
 * vue-chat and decides who an `@here` reaches. The default (Pvngu\Chat\Presence\CachePresence) remembers what each
 * browser tab reports for a couple of minutes; vue-chat sends a heartbeat while it's open. Bind your own in
 * config('laravel-chat.presence.driver') if your app already tracks presence.
 */
interface ChatPresence
{
    public const ONLINE = 'online';

    public const AWAY = 'away';

    /**
     * The person is around, on one of their tabs or devices ($connection, e.g. the Echo socket id). $status sets that
     * connection's status; null keeps it (a new connection is online). Online on any connection makes them online.
     * Returns whether their overall status changed.
     */
    public function touch(Model $user, ?string $status = null, ?string $connection = null): bool;

    /**
     * The statuses of these people (public ids) in $viewer's tenant: only the ones around are listed.
     *
     * @param  array<int, string>  $publicIds
     * @return array<string, string> public id => 'online' | 'away'
     */
    public function statuses(Model $viewer, array $publicIds): array;
}
