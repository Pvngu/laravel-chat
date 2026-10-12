<?php

namespace Pvngu\Chat\Contracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * How the chat knows your users. Every app models people differently (hashed ids, avatars, companies…); this is the
 * one place to describe yours. Bind your implementation in config('laravel-chat.user_provider').
 */
interface ChatUserProvider
{
    /** The id the browser sees for a user (e.g. a hashed id). Stable and unique. */
    public function publicId(Model $user): string;

    /** The user with this public id, or null when there is none. */
    public function find(string $publicId): ?Model;

    /**
     * How the chat shows a user.
     *
     * @return array{id: string, name: string, email: string|null, avatarUrl: string|null}
     */
    public function present(Model $user): array;

    /** Tenancy: people only see conversations and other people with the same key. '' when not multi-tenant. */
    public function tenant(Model $user): string;

    /**
     * People $user can start a conversation with or add to one ($user not included).
     *
     * @return Collection<int, Model>
     */
    public function available(Model $user): Collection;
}
