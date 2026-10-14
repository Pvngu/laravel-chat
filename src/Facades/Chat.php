<?php

namespace Pvngu\Chat\Facades;

use Illuminate\Support\Facades\Facade;
use Pvngu\Chat\ChatManager;

/**
 * @method static \Pvngu\Chat\Models\Conversation direct(\Illuminate\Database\Eloquent\Model $user, \Illuminate\Database\Eloquent\Model $other)
 * @method static \Pvngu\Chat\Models\Conversation group(\Illuminate\Database\Eloquent\Model $creator, iterable $members, ?string $name = null)
 * @method static \Pvngu\Chat\Models\Conversation channel(\Illuminate\Database\Eloquent\Model $creator, string $name, bool $isPrivate = false, ?string $description = null)
 * @method static \Pvngu\Chat\Models\Message send(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $sender, string $body, array $files = [], ?\Pvngu\Chat\Models\Message $replyTo = null, ?\Pvngu\Chat\Models\Message $forwardedFrom = null, ?array $gif = null)
 * @method static \Pvngu\Chat\Models\Conversation update(\Pvngu\Chat\Models\Conversation $conversation, array $changes)
 * @method static void setNotifications(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user, string $level, ?\DateTimeInterface $mutedUntil = null)
 * @method static \Illuminate\Support\Collection recipients(\Pvngu\Chat\Models\Message $message)
 * @method static string presenceChannel(\Illuminate\Database\Eloquent\Model $user)
 * @method static \Pvngu\Chat\Models\Participant addParticipant(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user, string $role = 'member', bool $notify = true)
 * @method static \Pvngu\Chat\Models\Participant addMember(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user, ?\Illuminate\Database\Eloquent\Model $by = null)
 * @method static void removeMember(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user, ?\Illuminate\Database\Eloquent\Model $by = null, ?\Illuminate\Database\Eloquent\Model $successor = null)
 * @method static void setRole(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user, string $role, ?\Illuminate\Database\Eloquent\Model $by = null)
 * @method static \Pvngu\Chat\Models\Reminder remind(\Illuminate\Database\Eloquent\Model $user, \Pvngu\Chat\Models\Message $message, \DateTimeInterface $at)
 * @method static void forgetReminder(\Illuminate\Database\Eloquent\Model $user, \Pvngu\Chat\Models\Message $message)
 * @method static bool sendReminder(\Pvngu\Chat\Models\Reminder $reminder)
 * @method static int sendDueReminders()
 * @method static bool isLastAdmin(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user)
 *
 * @see ChatManager
 */
class Chat extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ChatManager::class;
    }
}
