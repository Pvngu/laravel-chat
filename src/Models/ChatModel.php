<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Support\ChatConfig;

// Table names come from the configured prefix
abstract class ChatModel extends Model
{
    protected string $baseTable;

    protected $guarded = [];

    public function getTable(): string
    {
        return ChatConfig::table($this->baseTable);
    }
}
