<?php

use Pvngu\Chat\Presence\CachePresence;
use Pvngu\Chat\Users\DefaultChatUserProvider;

return [

    /*
    | The model people chat as. Null uses your auth provider's model (config/auth.php).
    */
    'user_model' => null,

    /*
    | How users are identified, shown, scoped and listed. Extend Pvngu\Chat\Users\DefaultChatUserProvider (or implement
    | Pvngu\Chat\Contracts\ChatUserProvider) when the attributes below aren't enough, e.g. to expose hashed ids.
    */
    'user_provider' => DefaultChatUserProvider::class,

    // Attributes the default user provider reads
    'user_attributes' => [
        'name' => 'name',
        'email' => 'email',
        'avatar' => null,   // e.g. 'profile_photo_url'
        'tenant' => null,   // e.g. 'company_id': people only see conversations and people with the same value
    ],

    /*
    | The type of your users table's primary key, used by the migration: 'bigint', 'uuid', 'ulid' or 'string'.
    */
    'user_key_type' => 'bigint',

    'table_prefix' => 'chat_',

    'routes' => [
        'enabled' => true,
        'prefix' => 'api/chat',
        'middleware' => ['api', 'auth:sanctum'],
        'name' => 'chat.',
    ],

    'pagination' => [
        'conversations' => 30,
        'messages' => 50,
    ],

    'attachments' => [
        'disk' => null,             // null uses filesystems.default
        'directory' => 'chat-attachments',
        'max_size_kb' => 10240,     // keep in sync with vue-chat's config.maxFileSizeMb
        'max_files' => 12,          // and config.maxAttachments
        'url_ttl_minutes' => 60,    // signed links (or temporary URLs on disks that support them, like S3)
    ],

    'gifs' => [
        // GIF messages can only point at media on these hosts, over HTTPS. The defaults are KLIPY's media servers, for
        // vue-chat's createKlipyGifs(); an empty list turns GIF messages off.
        'media_hosts' => ['static.klipy.com', 'static1.klipy.com', 'static2.klipy.com'],
    ],

    'presence' => [
        // Who counts as online for `@here` mentions. The default counts anyone who used the chat within `ttl` seconds
        // (vue-chat sends a heartbeat every minute while the page is open); implement Pvngu\Chat\Contracts\ChatPresence
        // to use your app's own presence instead.
        'driver' => CachePresence::class,
        'ttl' => 120,
        'store' => null,            // cache store; null uses the default
    ],

    'broadcasting' => [
        'enabled' => true,
        'channel_prefix' => 'chat',  // private channels: chat.conversation.{id} and chat.user.{userId}
        'connection' => null,        // queue connection for the broadcast jobs; null uses the default
        'queue' => null,
    ],

];
