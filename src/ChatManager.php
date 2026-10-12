<?php

namespace Pvngu\Chat;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Events\ConversationJoined;
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
    ): Message {
        $disk = config('laravel-chat.attachments.disk') ?? config('filesystems.default');
        $directory = trim((string) config('laravel-chat.attachments.directory', 'chat-attachments'), '/');
        $stored = [];

        try {
            $message = DB::transaction(function () use ($conversation, $sender, $body, $files, $replyTo, $forwardedFrom, $disk, $directory, &$stored) {
                $participant = $this->addParticipant($conversation, $sender, notify: false);

                /** @var Message $message */
                $message = $conversation->messages()->create([
                    'sender_id' => $sender->getKey(),
                    'body' => $body,
                    'reply_to_id' => $replyTo?->id,
                    'forwarded_from_id' => $forwardedFrom?->id,
                ]);

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
}
