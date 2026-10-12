<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Carbon;
use Pvngu\Chat\Support\ChatConfig;

/**
 * A direct message, a group conversation or a channel.
 *
 * @property int $id
 * @property ConversationType $type
 * @property string|null $name
 * @property string|null $description
 * @property bool $is_private
 * @property string $tenant
 * @property string|null $direct_key
 * @property Carbon|null $last_message_at
 * @property int|null $unread_count Set by withViewerState()
 * @property int|null $viewer_participant_id Set by withViewerState()
 */
class Conversation extends ChatModel
{
    protected string $baseTable = 'conversations';

    protected function casts(): array
    {
        return [
            'type' => ConversationType::class,
            'is_private' => 'boolean',
            'last_message_at' => 'datetime',
        ];
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->latestOfMany();
    }

    public function isDirect(): bool
    {
        return $this->type === ConversationType::Direct;
    }

    public function isChannel(): bool
    {
        return $this->type === ConversationType::Channel;
    }

    public function isPublicChannel(): bool
    {
        return $this->isChannel() && ! $this->is_private;
    }

    public function participantFor(Model $user): ?Participant
    {
        if ($this->relationLoaded('participants')) {
            return $this->participants->first(fn (Participant $p) => (string) $p->user_id === (string) $user->getKey());
        }

        return $this->participants()->where('user_id', $user->getKey())->first();
    }

    /**
     * Adds what $user sees of each conversation: their participation (viewer_* columns, null when they aren't a
     * member, e.g. in a public channel) and how many messages they haven't read (unread_count).
     */
    public function scopeWithViewerState(Builder $query, Model $user): void
    {
        $conversations = $this->getTable();
        $participants = ChatConfig::table('participants');
        $messages = ChatConfig::table('messages');
        $userKey = $user->getKey();

        $query
            ->leftJoin("$participants as viewer", function ($join) use ($conversations, $userKey) {
                $join->on('viewer.conversation_id', '=', "$conversations.id")->where('viewer.user_id', '=', $userKey);
            })
            ->select("$conversations.*")
            ->addSelect([
                'viewer.id as viewer_participant_id',
                'viewer.role as viewer_role',
                'viewer.pinned_at as viewer_pinned_at',
                'viewer.favorited_at as viewer_favorited_at',
                'viewer.archived_at as viewer_archived_at',
            ])
            ->selectSub(function (QueryBuilder $unread) use ($messages, $conversations, $userKey) {
                $unread->from($messages)
                    ->selectRaw('count(*)')
                    ->whereColumn("$messages.conversation_id", "$conversations.id")
                    ->whereNotNull('viewer.id')
                    ->whereRaw("$messages.id > coalesce(viewer.last_read_message_id, 0)")
                    ->whereNull("$messages.deleted_at")
                    ->where(fn ($q) => $q->whereNull("$messages.sender_id")->orWhere("$messages.sender_id", '!=', $userKey));
            }, 'unread_count');
    }

    // Conversations (not channels) $user takes part in
    public function scopeConversationsOf(Builder $query, Model $user, string $tenant): void
    {
        $query->withViewerState($user)
            ->where($this->qualifyColumn('tenant'), $tenant)
            ->whereIn($this->qualifyColumn('type'), [ConversationType::Direct->value, ConversationType::Group->value])
            ->whereNotNull('viewer.id');
    }

    // Channels $user is in, plus the public ones they could join
    public function scopeChannelsVisibleTo(Builder $query, Model $user, string $tenant): void
    {
        $query->withViewerState($user)
            ->where($this->qualifyColumn('tenant'), $tenant)
            ->where($this->qualifyColumn('type'), ConversationType::Channel->value)
            ->where(fn ($q) => $q->whereNotNull('viewer.id')->orWhere($this->qualifyColumn('is_private'), false));
    }
}
