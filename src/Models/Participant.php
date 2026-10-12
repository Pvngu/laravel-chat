<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pvngu\Chat\Support\ChatConfig;

/**
 * Someone in a conversation, with their own state in it: role, read position, pinned/favorite/archived.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|string $user_id
 * @property string $role
 * @property int|null $last_read_message_id
 */
class Participant extends ChatModel
{
    protected string $baseTable = 'participants';

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'favorited_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }
}
