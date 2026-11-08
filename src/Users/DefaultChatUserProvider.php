<?php

namespace Pvngu\Chat\Users;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Contracts\SearchesChatUsers;
use Pvngu\Chat\Support\ChatConfig;
use Pvngu\Chat\Support\Like;

/**
 * Reads the attributes in config('laravel-chat.user_attributes'). Extend it to change one part, e.g. publicId() to
 * expose hashed ids, or available() to search and limit large user lists.
 */
class DefaultChatUserProvider implements ChatUserProvider, SearchesChatUsers
{
    /** @var array<string, bool> Whether the user table has the name attribute as a column, by table */
    private static array $nameColumns = [];

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

    // Searches the name attribute when it's a column of the users table; a computed name (an accessor) can't be
    // searched by the database, so then nothing matches by name (override this to search your own columns)
    public function whereNameContains(Builder $users, string $term): void
    {
        $column = config('laravel-chat.user_attributes.name', 'name');
        $table = $users->getModel()->getTable();
        $hasColumn = self::$nameColumns["$table.$column"] ??= Schema::connection($users->getModel()->getConnectionName())
            ->hasColumn($table, $column);

        if ($hasColumn) {
            Like::contains($users->getQuery(), $users->qualifyColumn($column), $term);
        } else {
            $users->whereRaw('1 = 0');
        }
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
