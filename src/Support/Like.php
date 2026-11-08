<?php

namespace Pvngu\Chat\Support;

use Illuminate\Contracts\Database\Query\Builder;

/**
 * Case-insensitive "contains" matching with LIKE, the same on MySQL, PostgreSQL and SQLite: what was typed is
 * lowercased, and its % and _ match themselves (escaped with !, which no database treats specially in a string).
 */
final class Like
{
    public static function contains(Builder $query, string $column, string $term, string $boolean = 'and'): void
    {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($term));
        $wrapped = $query->getGrammar()->wrap($column);
        $query->whereRaw("lower($wrapped) like ? escape '!'", ["%$escaped%"], $boolean);
    }
}
