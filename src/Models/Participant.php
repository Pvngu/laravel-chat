<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pvngu\Chat\Casts\UtcDatetime;
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
            'muted_until' => UtcDatetime::class, // UTC in the database
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

    public const ADMIN = 'admin';

    public const MEMBER = 'member';

    public function isAdmin(): bool
    {
        return $this->role === self::ADMIN;
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
