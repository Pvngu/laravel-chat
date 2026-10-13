<?php

namespace Pvngu\Chat;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Events\ConversationJoined;
use Pvngu\Chat\Events\ConversationUpdated;
use Pvngu\Chat\Events\MessageDeleted;
use Pvngu\Chat\Events\MessageSent;
use Pvngu\Chat\Events\MessageUpdated;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\ConversationType;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\Participant;
use RuntimeException;
use Throwable;

/**
 * Everything the chat does, for the HTTP API and for your own code (e.g. Chat::send() for a system message).
 * It doesn't check permissions; the API's policies do that before calling it.
 */
class ChatManager
{
    public function __construct(private readonly ChatUserProvider $users) {}

    public function users(): ChatUserProvider
    {
        return $this->users;
    }

    // ── Starting conversations ──────────────────────────────────────────────

    /** The direct conversation between two people, started when they don't have one yet. */
    public function direct(Model $user, Model $other): Conversation
    {
        $tenant = $this->users->tenant($user);
        $keys = [(string) $user->getKey(), (string) $other->getKey()];
        sort($keys, SORT_STRING);
        $directKey = implode(':', $keys);

        $find = fn () => Conversation::query()->where('tenant', $tenant)->where('direct_key', $directKey)->first();

        if ($existing = $find()) {
            return $existing;
        }

        try {
            return DB::transaction(function () use ($user, $other, $tenant, $directKey) {
                $conversation = Conversation::query()->create([
                    'type' => ConversationType::Direct,
                    'is_private' => true,
                    'tenant' => $tenant,
                    'direct_key' => $directKey,
                    'created_by' => $user->getKey(),
                ]);
                $this->addParticipant($conversation, $user, 'admin', notify: false);
                $this->addParticipant($conversation, $other);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // The other person started it at the same moment
            return $find();
        }
    }

    /** @param iterable<Model> $members */
    public function group(Model $creator, iterable $members, ?string $name = null): Conversation
    {
        return DB::transaction(function () use ($creator, $members, $name) {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Group,
                'name' => $name ?: null,
                'is_private' => true,
                'tenant' => $this->users->tenant($creator),
                'created_by' => $creator->getKey(),
            ]);
            $this->addParticipant($conversation, $creator, 'admin', notify: false);
            foreach ($members as $member) {
                $this->addParticipant($conversation, $member);
            }

            return $conversation;
        });
    }

    public function channel(Model $creator, string $name, bool $isPrivate = false, ?string $description = null): Conversation
    {
        return DB::transaction(function () use ($creator, $name, $isPrivate, $description) {
            $conversation = Conversation::query()->create([
                'type' => ConversationType::Channel,
                'name' => $name,
                'description' => $description,
                'is_private' => $isPrivate,
                'tenant' => $this->users->tenant($creator),
                'created_by' => $creator->getKey(),
            ]);
            $this->addParticipant($conversation, $creator, 'admin', notify: false);

            return $conversation;
        });
    }

    // ── Members ─────────────────────────────────────────────────────────────

    /**
     * Adds someone; nothing changes when they're already in. Earlier messages count as read for them. They're told
     * in real time (ConversationJoined) unless $notify is false.
     */
    public function addParticipant(Conversation $conversation, Model $user, string $role = 'member', bool $notify = true): Participant
    {
        $participant = Participant::query()->firstOrCreate(
            ['conversation_id' => $conversation->id, 'user_id' => $user->getKey()],
            ['role' => $role, 'last_read_message_id' => $conversation->messages()->max('id')],
        );
        $conversation->unsetRelation('participants');

        if ($participant->wasRecentlyCreated && $notify) {
            ConversationJoined::dispatch($conversation, $user);
        }

        return $participant;
    }

    public function removeParticipant(Conversation $conversation, Model $user): void
    {
        $conversation->participants()->where('user_id', $user->getKey())->delete();
        $conversation->unsetRelation('participants');
    }

    // ── Messages ────────────────────────────────────────────────────────────

    /**
     * Sends a message, storing any uploaded files on the configured disk. Posting in a public channel joins it.
     *
     * @param  array<int, UploadedFile>  $files
     */
    public function send(
        Conversation $conversation,
        Model $sender,
        string $body,
        array $files = [],
        ?Message $replyTo = null,
        ?Message $forwardedFrom = null,
        ?array $gif = null, // see MessageResource for the shape; the request validates where its media comes from
    ): Message {
        $disk = config('laravel-chat.attachments.disk') ?? config('filesystems.default');
        $directory = trim((string) config('laravel-chat.attachments.directory', 'chat-attachments'), '/');
        $stored = [];

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $body, $files, $replyTo, $forwardedFrom, $gif, $disk, $directory, &$stored) {
                $participant = $this->addParticipant($conversation, $sender, notify: false);

                /** @var Message $message */
                $message = $conversation->messages()->create([
                    'sender_id' => $sender->getKey(),
                    'body' => $body,
                    'gif' => $gif,
                    'reply_to_id' => $replyTo?->id,
                    'forwarded_from_id' => $forwardedFrom?->id,
                ]);

                $this->syncMentions($message);

                foreach ($files as $file) {
                    $path = $file->store("$directory/{$conversation->id}", $disk);
                    if ($path === false) {
                        throw new RuntimeException("Could not store the attachment {$file->getClientOriginalName()}.");
                    }
                    $stored[] = $path;
                    $message->attachments()->create([
                        'disk' => $disk,
                        'path' => $path,
                        'name' => $file->getClientOriginalName(),
                        'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
                        'size' => $file->getSize(),
                    ]);
                }

                $conversation->forceFill(['last_message_at' => $message->created_at])->save();
                // Your own message isn't unread for you, and a new message brings an archived chat back
                $participant->forceFill(['last_read_message_id' => $message->id])->save();
                $conversation->participants()->whereNotNull('archived_at')->update(['archived_at' => null]);

                return $message;
            });
        } catch (Throwable $exception) {
            foreach ($stored as $path) {
                Storage::disk($disk)->delete($path);
            }
            throw $exception;
        }

        MessageSent::dispatch($message);

        return $message;
    }

    public function edit(Message $message, string $body): Message
    {
        $message->forceFill(['body' => $body, 'edited_at' => now()])->save();
        $this->syncMentions($message);
        MessageUpdated::dispatch($message);

        return $message;
    }

    // Soft delete: the row (and its files) stay, the message disappears from the chat
    public function delete(Message $message): void
    {
        $kind = $message->conversation->isChannel() ? 'channel' : 'conversation';
        $message->delete();
        MessageDeleted::dispatch($message->conversation_id, $kind, (string) $message->id);
    }

    public function react(Message $message, Model $user, string $emoji): Message
    {
        $message->reactions()->firstOrCreate(['user_id' => $user->getKey(), 'emoji' => $emoji]);

        return $this->reactionsChanged($message);
    }

    public function unreact(Message $message, Model $user, string $emoji): Message
    {
        $message->reactions()->where('user_id', $user->getKey())->where('emoji', $emoji)->delete();

        return $this->reactionsChanged($message);
    }

    public function pin(Message $message, bool $pinned): Message
    {
        $message->forceFill(['pinned_at' => $pinned ? now() : null])->save();
        MessageUpdated::dispatch($message);

        return $message;
    }

    // ── Per-person state ────────────────────────────────────────────────────

    public function markRead(Conversation $conversation, Model $user): void
    {
        $conversation->participants()
            ->where('user_id', $user->getKey())
            ->update(['last_read_message_id' => $conversation->messages()->max('id')]);
    }

    /**
     * Change a conversation's or channel's details: `name`, `description`, and for channels `isPrivate`.
     *
     * @param  array{name?: ?string, description?: ?string, isPrivate?: bool}  $changes
     */
    public function update(Conversation $conversation, array $changes): Conversation
    {
        $attributes = array_filter([
            'name' => array_key_exists('name', $changes) ? $changes['name'] : false,
            'description' => array_key_exists('description', $changes) ? $changes['description'] : false,
            'is_private' => $conversation->isChannel() && array_key_exists('isPrivate', $changes) ? (bool) $changes['isPrivate'] : false,
        ], fn ($value) => $value !== false);

        $conversation->fill($attributes);
        if ($conversation->isDirty()) {
            $conversation->save();
            ConversationUpdated::dispatch($conversation);
        }

        return $conversation;
    }

    /**
     * How a person hears about new messages in a conversation: 'all', 'mentions' or 'none', and optionally nothing
     * at all until a time (a temporary mute).
     */
    public function setNotifications(Conversation $conversation, Model $user, string $level, ?DateTimeInterface $mutedUntil = null): void
    {
        $conversation->participants()
            ->where('user_id', $user->getKey())
            ->update(['notifications' => $level, 'muted_until' => $mutedUntil]);
    }

    /**
     * The people to notify about a message (by push, email, …): its conversation's members except the sender, as
     * their notification settings ask. Use it from a MessageSent listener.
     *
     * @return Collection<int, Model>
     */
    public function recipients(Message $message): Collection
    {
        $mentioned = $message->mentions()->pluck('user_id')->map(fn ($id) => (string) $id)->all();

        return $message->conversation->participants()->with('user')->get()
            ->filter(fn (Participant $participant) => (string) $participant->user_id !== (string) $message->sender_id)
            ->filter(fn (Participant $participant) => $participant->wantsNotification(in_array((string) $participant->user_id, $mentioned, true)))
            ->map(fn (Participant $participant) => $participant->user)
            ->filter()
            ->values();
    }

    /** @param  'pinned'|'favorited'|'archived'  $flag */
    public function setFlag(Conversation $conversation, Model $user, string $flag, bool $on): void
    {
        $conversation->participants()
            ->where('user_id', $user->getKey())
            ->update([$flag.'_at' => $on ? now() : null]);
    }

    private function reactionsChanged(Message $message): Message
    {
        $message->unsetRelation('reactions');
        MessageUpdated::dispatch($message);

        return $message;
    }

    // Record who the text mentions with `<@id>`: people in the conversation's tenant (up to 50 per message)
    private function syncMentions(Message $message): void
    {
        preg_match_all(Message::MENTION_PATTERN, $message->body, $matches);
        $tenant = $message->conversation()->value('tenant');
        $people = collect(array_slice(array_unique($matches[1]), 0, 50))
            ->map(fn (string $publicId) => $this->users->find($publicId))
            ->filter(fn (?Model $user) => $user !== null && $this->users->tenant($user) === $tenant)
            ->unique(fn (Model $user) => (string) $user->getKey());

        $message->mentions()->delete();
        foreach ($people as $person) {
            $message->mentions()->create(['conversation_id' => $message->conversation_id, 'user_id' => $person->getKey()]);
        }
        $message->unsetRelation('mentions');
    }
}
