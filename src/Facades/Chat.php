<?php

namespace Pvngu\Chat\Facades;

use Illuminate\Support\Facades\Facade;
use Pvngu\Chat\ChatManager;

/**
 * @method static \Pvngu\Chat\Models\Conversation direct(\Illuminate\Database\Eloquent\Model $user, \Illuminate\Database\Eloquent\Model $other)
 * @method static \Pvngu\Chat\Models\Conversation group(\Illuminate\Database\Eloquent\Model $creator, iterable $members, ?string $name = null)
 * @method static \Pvngu\Chat\Models\Conversation channel(\Illuminate\Database\Eloquent\Model $creator, string $name, bool $isPrivate = false, ?string $description = null)
 * @method static \Pvngu\Chat\Models\Message send(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $sender, string $body, array $files = [], ?\Pvngu\Chat\Models\Message $replyTo = null, ?\Pvngu\Chat\Models\Message $forwardedFrom = null)
 * @method static \Pvngu\Chat\Models\Participant addParticipant(\Pvngu\Chat\Models\Conversation $conversation, \Illuminate\Database\Eloquent\Model $user, string $role = 'member', bool $notify = true)
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
