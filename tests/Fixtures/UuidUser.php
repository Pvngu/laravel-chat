<?php

namespace Pvngu\Chat\Tests\Fixtures;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Foundation\Auth\User as Authenticatable;

class UuidUser extends Authenticatable
{
    use HasUuids;

    protected $guarded = [];

    protected $table = 'users';
}
