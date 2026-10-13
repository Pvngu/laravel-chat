<?php

namespace Pvngu\Chat\Models;

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
 * @property int|null $reply_to_id
 * @property int|null $forwarded_from_id
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
            'gif' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'sender_id');
    }

    // Deleted originals are kept so the quote can say whose message it was
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_id')->withTrashed();
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(Mention::class);
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(Attachment::class);
    }

    public function reactions(): HasMany
    {
        return $this->hasMany(Reaction::class)->orderBy('id');
    }

    // What the sidebar shows for this message
    // A mention in a message's text: `<@id>`, with the person's public id (ChatUserProvider::publicId)
    public const MENTION_PATTERN = '/<@([^<>\s]{1,191})>/';

    // The text as people read it, with mentions as "@Name" (from the loaded mentions)
    public function displayText(): string
    {
        if (! str_contains($this->body, '<@')) {
            return $this->body;
        }
        $users = app(ChatUserProvider::class);
        $names = $this->mentions
            ->filter(fn (Mention $mention) => $mention->user !== null)
            ->mapWithKeys(fn (Mention $mention) => [$users->publicId($mention->user) => $users->present($mention->user)['name']]);

        return (string) preg_replace_callback(
            self::MENTION_PATTERN,
            fn (array $match) => '@'.($names[$match[1]] ?? 'unknown'),
            $this->body,
        );
    }

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
        return ['sender', 'replyTo.sender', 'replyTo.mentions.user', 'attachments', 'reactions.user', 'mentions.user'];
    }
}
