<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
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
            'muted_until' => 'datetime',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    // Notifications are off, for good or for now
    public function isMuted(): bool
    {
        return $this->notifications === 'none' || ($this->muted_until !== null && $this->muted_until->isFuture());
    }

    // Whether this person wants to hear about a new message (that mentions them or not)
    public function wantsNotification(bool $isMentioned): bool
    {
        if ($this->isMuted()) {
            return false;
        }

        return $this->notifications === 'all' || ($this->notifications === 'mentions' && $isMentioned);
    }
}
