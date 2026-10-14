<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pvngu\Chat\Support\ChatConfig;

/**
 * A message someone saved for later.
 *
 * @property int $id
 * @property int $message_id
 * @property int|string $user_id
 * @property Carbon $created_at
 */
class SavedMessage extends ChatModel
{
    protected string $baseTable = 'saved_messages';

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
