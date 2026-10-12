<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Pvngu\Chat\Support\ChatConfig;

class MessageDeleted extends ChatEvent
{
    public function __construct(public int $conversationId, public string $kind, public string $messageId)
    {
        parent::__construct();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChatConfig::channel('conversation.'.$this->conversationId))];
    }

    public function broadcastAs(): string
    {
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return ['kind' => $this->kind, 'conversationId' => $this->conversationId, 'messageId' => $this->messageId];
    }
}
