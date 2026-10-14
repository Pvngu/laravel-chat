<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pvngu\Chat\Support\ChatConfig;

/**
 * Someone following a thread: who started it, replied to it, or was mentioned in it. Replies after
 * last_read_reply_id are unread for them.
 *
 * @property int $id
 * @property int $thread_id
 * @property int|string $user_id
 * @property int|null $last_read_reply_id
 * @property int|null $unread_count Set by ThreadController
 */
class ThreadFollower extends ChatModel
{
    protected string $baseTable = 'thread_followers';

    /** @return BelongsTo<Message, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'thread_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
