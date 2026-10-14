<?php

namespace Pvngu\Chat;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Contracts\ChatPresence;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Events\ConversationJoined;
use Pvngu\Chat\Events\ConversationUpdated;
use Pvngu\Chat\Events\MessageDeleted;
use Pvngu\Chat\Events\MessageSent;
use Pvngu\Chat\Events\MessageUpdated;
use Pvngu\Chat\Events\ReminderDue;
use Pvngu\Chat\Jobs\SendReminder;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\ConversationType;
use Pvngu\Chat\Models\Mention;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\Participant;
use Pvngu\Chat\Models\Reminder;
use Pvngu\Chat\Support\ChatConfig;
use RuntimeException;
use Throwable;

/**
 * Everything the chat does, for the HTTP API and for your own code (e.g. Chat::send() for a system message).
 * It doesn't check permissions; the API's policies do that before calling it.
 */
class ChatManager
{
    public function __construct(
        private readonly ChatUserProvider $users,
        private readonly ChatPresence $presence,
    ) {}

    public function users(): ChatUserProvider
    {
        return $this->users;
    }

    // The presence channel of $user's tenant: chat.presence.{a hash of the tenant}
    public function presenceChannel(Model $user): string
    {
        return ChatConfig::channel('presence.'.substr(sha1('laravel-chat|'.$this->users->tenant($user)), 0, 16));
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
                $this->addParticipant($conversation, $user, Participant::ADMIN, notify: false);
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
            $this->addParticipant($conversation, $creator, Participant::ADMIN, notify: false);
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
            $this->addParticipant($conversation, $creator, Participant::ADMIN, notify: false);

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
        // The creator coming back is an admin again
        if ($conversation->isCreator($user)) {
            $role = Participant::ADMIN;
        }
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

    /**
     * Adds someone to a group or channel and says so in the chat: "joined" when they add themselves ($by is them or
     * null), otherwise "$by added them". Nothing happens when they're already in.
     */
    public function addMember(Conversation $conversation, Model $user, ?Model $by = null): Participant
    {
        $participant = $this->addParticipant($conversation, $user);

        if ($participant->wasRecentlyCreated) {
            $joined = $by === null || $by->is($user);
            $this->recordMembership($conversation, $by ?? $user, $joined ? Message::EVENT_JOINED : Message::EVENT_ADDED, $user);
        }

        return $participant;
    }

    /**
     * Takes someone out of a group or channel and says so in the chat: "left" when they leave ($by is them or null),
     * otherwise "$by removed them". Nothing happens when they aren't in.
     */
    public function removeMember(Conversation $conversation, Model $user, ?Model $by = null, ?Model $successor = null): void
    {
        $participant = $conversation->participants()->where('user_id', $user->getKey())->first();
        if ($participant === null) {
            return;
        }

        // The last admin going: someone else takes over, the one they chose or whoever has been in it longest
        $others = $conversation->participants()->where('user_id', '!=', $user->getKey());
        if ($participant->isAdmin() && ! (clone $others)->where('role', Participant::ADMIN)->exists()) {
            $next = $successor ? (clone $others)->where('user_id', $successor->getKey())->first() : null;
            $next ??= (clone $others)->with('user')->first();
            if ($next !== null) {
                $next->forceFill(['role' => Participant::ADMIN])->save();
                if ($next->user) {
                    $this->recordMembership($conversation, $user, Message::EVENT_PROMOTED, $next->user);
                }
            }
        }

        $left = $by === null || $by->is($user);
        $this->recordMembership($conversation, $by ?? $user, $left ? Message::EVENT_LEFT : Message::EVENT_REMOVED, $user);
        $this->removeParticipant($conversation, $user);
    }

    /**
     * Makes someone in a group or channel an admin, or a member again, and says so in the chat. A chat keeps at least
     * one admin: demoting the last one does nothing (the API refuses it).
     */
    public function setRole(Conversation $conversation, Model $user, string $role, ?Model $by = null): void
    {
        $participant = $conversation->participants()->where('user_id', $user->getKey())->first();
        if ($participant === null || $participant->role === $role) {
            return;
        }
        if ($role !== Participant::ADMIN && $this->isLastAdmin($conversation, $user)) {
            return;
        }

        $participant->forceFill(['role' => $role])->save();
        $conversation->unsetRelation('participants');
        $event = $role === Participant::ADMIN ? Message::EVENT_PROMOTED : Message::EVENT_DEMOTED;
        $this->recordMembership($conversation, $by ?? $user, $event, $user);
    }

    // Whether $user is the only admin left
    public function isLastAdmin(Conversation $conversation, Model $user): bool
    {
        $admins = $conversation->participants()->where('role', Participant::ADMIN)->pluck('user_id');

        return $admins->count() === 1 && (string) $admins->first() === (string) $user->getKey();
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
                // Posting in a public channel joins it
                if ($participant->wasRecentlyCreated) {
                    $this->recordMembership($conversation, $sender, Message::EVENT_JOINED, $sender);
                }

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

    // ── Reminders ───────────────────────────────────────────────────────────

    // Remind them of a message at a time. One reminder per person and message: a new time replaces the old one.
    // A queued job, delayed until then, fires ReminderDue.
    public function remind(Model $user, Message $message, DateTimeInterface $at): Reminder
    {
        /** @var Reminder $reminder */
        $reminder = Reminder::query()->updateOrCreate(
            ['user_id' => $user->getKey(), 'message_id' => $message->id],
            ['remind_at' => $at, 'sent_at' => null],
        );
        SendReminder::dispatch($reminder->id)->delay($reminder->remind_at);

        return $reminder;
    }

    public function forgetReminder(Model $user, Message $message): void
    {
        Reminder::query()->where('user_id', $user->getKey())->where('message_id', $message->id)->delete();
    }

    // Fires ReminderDue when its time has come. A reminder whose message was deleted, or whose chat they can't see
    // anymore, is dropped instead. Returns whether it was sent.
    public function sendReminder(Reminder $reminder): bool
    {
        if ($reminder->sent_at !== null || $reminder->remind_at->isFuture()) {
            return false; // sent, or moved to a later time (its own job comes then)
        }

        $message = $reminder->message;
        $user = $reminder->user;
        if (! $message || ! $user || $message->trashed() || Gate::forUser($user)->denies('view', $message->conversation)) {
            $reminder->delete();

            return false;
        }

        // Claimed first, so a second worker (or chat:send-reminders) doesn't send it again
        $claimed = Reminder::query()->whereKey($reminder->id)->whereNull('sent_at')->update(['sent_at' => now()]);
        if ($claimed === 0) {
            return false;
        }

        ReminderDue::dispatch($reminder->refresh());

        return true;
    }

    // Every reminder that's due and not sent: the queued jobs send them on time; this catches up on any they missed
    public function sendDueReminders(): int
    {
        $sent = 0;
        Reminder::query()
            ->whereNull('sent_at')
            ->where('remind_at', '<=', now())
            ->with(['message.conversation', 'user'])
            ->orderBy('id')
            ->each(function (Reminder $reminder) use (&$sent) {
                $sent += $this->sendReminder($reminder) ? 1 : 0;
            });

        return $sent;
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
        if ($message->isEvent()) {
            return collect(); // "joined", "left"… aren't worth a notification
        }

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

    /**
     * Which of these people (user keys) are online, not away, for `@here`.
     *
     * @param  array<int, int|string>  $keys
     * @return array<int, int|string>
     */
    private function onlineAmong(array $keys, Message $message): array
    {
        $sender = $message->sender;
        if ($keys === [] || $sender === null) {
            return [];
        }
        $people = ChatConfig::userModel()::query()->whereKey($keys)->get();
        $statuses = $this->presence->statuses($sender, $people->map(fn (Model $person) => $this->users->publicId($person))->all());

        return $people
            ->filter(fn (Model $person) => ($statuses[$this->users->publicId($person)] ?? null) === ChatPresence::ONLINE)
            ->map(fn (Model $person) => $person->getKey())
            ->values()
            ->all();
    }

    // A line in the chat for a membership change. It doesn't move the chat up the sidebar or count as unread.
    private function recordMembership(Conversation $conversation, Model $actor, string $event, Model $user): void
    {
        /** @var Message $message */
        $message = $conversation->messages()->create([
            'sender_id' => $actor->getKey(),
            'body' => '',
            'event' => $event,
            'event_user_id' => $user->getKey(),
        ]);
        // Everyone sees the line, including whoever made the change: their request doesn't return it
        $sent = new MessageSent($message);
        $sent->socket = null;
        event($sent);
    }

    private function reactionsChanged(Message $message): Message
    {
        $message->unsetRelation('reactions');
        MessageUpdated::dispatch($message);

        return $message;
    }

    // Record who the text mentions: people with `<@id>` (in the conversation's tenant, up to 50 per message), and the
    // other members with `<!everyone>`, or those online now with `<!here>`. Someone named and included by a group
    // mention counts once, as named.
    private function syncMentions(Message $message): void
    {
        preg_match_all(Message::MENTION_PATTERN, $message->body, $named);
        preg_match_all(Message::GROUP_MENTION_PATTERN, $message->body, $groups);
        $tenant = $message->conversation()->value('tenant');

        $mentioned = []; // user key => via
        collect(array_slice(array_unique($named[1]), 0, 50))
            ->map(fn (string $publicId) => $this->users->find($publicId))
            ->filter(fn (?Model $user) => $user !== null && $this->users->tenant($user) === $tenant)
            ->each(function (Model $user) use (&$mentioned) {
                $mentioned[(string) $user->getKey()] = [$user->getKey(), Mention::VIA_PERSON];
            });

        if ($groups[1] !== []) {
            $via = in_array('everyone', $groups[1], true) ? Mention::VIA_EVERYONE : Mention::VIA_HERE;
            $members = $message->conversation->participants()
                ->where('user_id', '!=', $message->sender_id)
                ->pluck('user_id')
                ->all();
            foreach ($via === Mention::VIA_HERE ? $this->onlineAmong($members, $message) : $members as $key) {
                $mentioned[(string) $key] ??= [$key, $via];
            }
        }

        $message->mentions()->delete();
        foreach (array_chunk(array_values($mentioned), 500) as $chunk) {
            Mention::query()->insert(array_map(fn (array $mention) => [
                'message_id' => $message->id,
                'conversation_id' => $message->conversation_id,
                'user_id' => $mention[0],
                'via' => $mention[1],
            ], $chunk));
        }
        $message->unsetRelation('mentions');
        $message->unsetRelation('personMentions');
    }
}
