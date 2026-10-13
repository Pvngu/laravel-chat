<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pvngu\Chat\Support\ChatConfig;

/**
 * A person a message mentions with `<@id>` in its text.
 *
 * @property int $message_id
 * @property int $conversation_id
 * @property int|string $user_id
 */
class Mention extends ChatModel
{
    public $timestamps = false;

    protected string $baseTable = 'mentions';

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
