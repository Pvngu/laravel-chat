<?php

namespace Pvngu\Chat\Contracts;

use Illuminate\Database\Eloquent\Builder;

/**
 * Optional for a ChatUserProvider: narrows a query of your user model to the people whose name contains $term. With
 * it, searching conversations (`GET conversations?search=`) also finds them by who's in them; without it, only by
 * their name. Like::contains() builds a case-insensitive pattern that works on MySQL, PostgreSQL and SQLite.
 */
interface SearchesChatUsers
{
    public function whereNameContains(Builder $users, string $term): void;
}
