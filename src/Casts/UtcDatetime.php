<?php

namespace Pvngu\Chat\Casts;

use DateTimeInterface;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A moment someone chose (a reminder's time, the end of a mute), kept as UTC in a DATETIME column and read back on the
 * app's clock. Kept on the app's clock instead, an app that isn't on UTC would store the two 1:30s of a night the clocks
 * go back the same way, and read the second one back an hour early.
 *
 * @implements CastsAttributes<Carbon, DateTimeInterface|string>
 */
class UtcDatetime implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?Carbon
    {
        return $value === null ? null : Carbon::parse($value, 'UTC')->setTimezone(date_default_timezone_get());
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return $value === null ? null : self::toStored($value);
    }

    // As the column holds it: for queries that write it without the model (a string is read on the app's clock)
    public static function toStored(DateTimeInterface|string $value): string
    {
        $time = $value instanceof DateTimeInterface ? Carbon::instance($value) : Carbon::parse($value);

        return $time->copy()->utc()->format('Y-m-d H:i:s');
    }
}
