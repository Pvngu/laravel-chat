<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Support\ChatConfig;

/**
 * @property int $id
 * @property int $conversation_id
 * @property int|string|null $sender_id
 * @property string $body
 * @property string|null $event joined, left, added, removed, promoted or demoted: a membership change shown in the chat
 * @property int|string|null $event_user_id who it happened to
 * @property int|null $reply_to_id
 * @property int|null $forwarded_from_id
 * @property int|null $thread_id the message whose thread it's a reply in
 * @property int $reply_count a thread's replies
 * @property Carbon|null $last_reply_at
 * @property Carbon|null $edited_at
 * @property Carbon|null $pinned_at
 * @property Carbon $created_at
 */
class Message extends ChatModel
{
    use SoftDeletes;

    protected string $baseTable = 'messages';

    protected function casts(): array
    {
        return [
            'edited_at' => 'datetime',
            'pinned_at' => 'datetime',
            'last_reply_at' => 'datetime',
            'gif' => 'array',
        ];
    }

    /** @return BelongsTo<Conversation, $this> */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    // Membership changes: someone joined or left, or the sender added or removed someone (event_user_id)
    public const EVENT_JOINED = 'joined';

    public const EVENT_LEFT = 'left';

    public const EVENT_ADDED = 'added';

    public const EVENT_REMOVED = 'removed';

    // The sender made someone (event_user_id) an admin, or a member again
    public const EVENT_PROMOTED = 'promoted';

    public const EVENT_DEMOTED = 'demoted';

    public function isEvent(): bool
    {
        return $this->event !== null;
    }

    /** @return BelongsTo<Model, $this> */
    public function eventUser(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'event_user_id');
    }

    /** @return BelongsTo<Model, $this> */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'sender_id');
    }

    // Deleted originals are kept so the quote can say whose message it was
    /** @return BelongsTo<Message, $this> */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    // Everyone the message mentions, including through @everyone and @here: unread counts and notifications
    /** @return HasMany<Mention, $this> */
    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    // The people mentioned by name, which is what's shown (an @everyone stays one token, however big the chat)
    /** @return HasMany<Mention, $this> */
    public function personMentions(): HasMany
    {
        return $this->hasMany(Mention::class)->where('via', Mention::VIA_PERSON);
    }

    /** @return HasMany<Attachment, $this> */
    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    // The message a reply is in the thread of
    /** @return BelongsTo<Message, $this> */
    public function thread(): BelongsTo
    {
        return $this->belongsTo(Message::class, 'thread_id');
    }

    /** @return HasMany<Message, $this> */
    public function replies(): HasMany
    {
        return $this->hasMany(Message::class, 'thread_id');
    }

    /** @return HasMany<ThreadFollower, $this> */
    public function followers(): HasMany
    {
        return $this->hasMany(ThreadFollower::class, 'thread_id');
    }

    public function isReply(): bool
    {
        return $this->thread_id !== null;
    }

    /** @return HasMany<Reminder, $this> */
    public function reminders(): HasMany
    {
        return $this->hasMany(Reminder::class);
    }

    /** @return HasMany<Reaction, $this> */
    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class)->orderBy('id');
    }

    // A mention in a message's text: `<@id>`, with the person's public id (ChatUserProvider::publicId)
    public const MENTION_PATTERN = '/<@([^<>\s]{1,191})>/';

    // Everyone in the chat (`<!everyone>`), or everyone online at the time (`<!here>`)
    public const GROUP_MENTION_PATTERN = '/<!(everyone|here)>/';

    // The text as people read it, with mentions as "@Name" (from the loaded person mentions), "@everyone" and "@here"
    public function displayText(): string
    {
        if (! str_contains($this->body, '<@') && ! str_contains($this->body, '<!')) {
            return $this->body;
        }
        $users = app(ChatUserProvider::class);
        $names = $this->personMentions
            ->filter(fn (Mention $mention) => $mention->user !== null)
            ->mapWithKeys(fn (Mention $mention) => [$users->publicId($mention->user) => $users->present($mention->user)['name']]);

        $text = (string) preg_replace_callback(
            self::MENTION_PATTERN,
            fn (array $match) => '@'.($names[$match[1]] ?? 'unknown'),
            $this->body,
        );

        return (string) preg_replace(self::GROUP_MENTION_PATTERN, '@$1', $text);
    }

    // What the sidebar shows for this message
    public function previewText(): string
    {
        if ($this->body !== '') {
            return $this->displayText();
        }
        if ($this->gif) {
            return 'GIF';
        }

        return $this->attachments->map(fn (Attachment $a) => "📎 {$a->name}")->implode(', ');
    }

    // Relations a MessageResource reads
    public static function presentationRelations(): array
    {
        return ['sender', 'eventUser', 'replyTo.sender', 'replyTo.personMentions.user', 'attachments', 'reactions.user', 'personMentions.user'];
    }
}
