<?php

namespace Pvngu\Chat\Users;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Support\ChatConfig;

/**
 * Reads the attributes in config('laravel-chat.user_attributes'). Extend it to change one part, e.g. publicId() to
 * expose hashed ids, or available() to search and limit large user lists.
 */
class DefaultChatUserProvider implements ChatUserProvider
{
    public function publicId(Model $user): string
    {
        return (string) $user->getKey();
    }

    public function publicIdForKey(int|string $key): string
    {
        return (string) $key;
    }

    public function find(string $publicId): ?Model
    {
        // An integer key never matches a non-numeric id (and PostgreSQL would reject the comparison)
        if (config('laravel-chat.user_key_type', 'bigint') === 'bigint' && ! ctype_digit($publicId)) {
            return null;
        }

        $model = ChatConfig::userModel();

        return $model::query()->find($publicId);
    }

    public function present(Model $user): array
    {
        $attributes = config('laravel-chat.user_attributes', []);

        return [
            'id' => $this->publicId($user),
            'name' => (string) data_get($user, $attributes['name'] ?? 'name', ''),
            'email' => isset($attributes['email']) ? data_get($user, $attributes['email']) : null,
            'avatarUrl' => isset($attributes['avatar']) ? data_get($user, $attributes['avatar']) : null,
        ];
    }

    public function tenant(Model $user): string
    {
        $attribute = config('laravel-chat.user_attributes.tenant');

        return $attribute ? (string) $user->getAttribute($attribute) : '';
    }

    public function available(Model $user): Collection
    {
        $attribute = config('laravel-chat.user_attributes.tenant');
        $model = ChatConfig::userModel();

        return $model::query()
            ->whereKeyNot($user->getKey())
            // The raw column value, in case the model casts it (e.g. to a hashed id)
            ->when($attribute, fn ($query) => $query->where($attribute, $user->getRawOriginal($attribute)))
            ->limit(1000)
            ->get()
            ->sortBy(fn (Model $person) => mb_strtolower($this->present($person)['name']))
            ->values();
    }
}
