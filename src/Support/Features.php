<?php

namespace Pvngu\Chat\Support;

// What the chat can do, each on unless config('laravel-chat.features') turns it off. A feature that's off has its
// routes answer 404 and its request fields refused; vue-chat reads the list (GET features) and leaves it out too.
final class Features
{
    public const ALL = [
        'channels',      // public and private channels, besides conversations
        'groups',        // group conversations, besides direct messages
        'threads',       // replies in threads, and the threads you follow
        'saved',         // saving messages for later
        'reminders',     // reminders on messages
        'reactions',     // emoji reactions
        'replies',       // replying to (quoting) a message
        'forwarding',    // forwarding messages
        'editing',       // editing your messages
        'deleting',      // deleting your messages
        'pinning',       // pinning messages in a chat
        'mentions',      // @mentions, @everyone and @here
        'attachments',   // files and photos
        'gifs',          // GIFs (with laravel-chat.gifs)
        'formatting',    // bold, lists, links… in the message box (vue-chat)
        'files',         // a chat's Files tab
        'presence',      // online, away and offline
        'notifications', // notification settings and muting, per chat
    ];

    public static function enabled(string $feature): bool
    {
        return (bool) (config("laravel-chat.features.$feature") ?? true);
    }

    /** @return array<string, bool> */
    public static function all(): array
    {
        return array_combine(self::ALL, array_map(self::enabled(...), self::ALL));
    }
}
