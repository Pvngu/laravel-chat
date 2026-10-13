<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pvngu\Chat\Support\ChatConfig;

/**
 * @property int $message_id
 * @property int|string $user_id
 * @property string $emoji
 */
class Reaction extends ChatModel
{
    public const UPDATED_AT = null;

    protected string $baseTable = 'reactions';

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
