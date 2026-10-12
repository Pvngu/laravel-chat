<?php

namespace Pvngu\Chat\Support;

use Illuminate\Database\Eloquent\Model;

final class ChatConfig
{
    /** @return class-string<Model> */
    public static function userModel(): string
    {
        return config('laravel-chat.user_model') ?? config('auth.providers.users.model');
    }

    public static function table(string $name): string
    {
        return config('laravel-chat.table_prefix', 'chat_').$name;
    }

    public static function broadcasts(): bool
    {
        return (bool) config('laravel-chat.broadcasting.enabled', true);
    }

    public static function channel(string $name): string
    {
        return config('laravel-chat.broadcasting.channel_prefix', 'chat').'.'.$name;
    }
}
