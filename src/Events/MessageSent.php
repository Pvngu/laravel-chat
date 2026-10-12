<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Support\ChatConfig;

class MessageSent extends ChatEvent
{
    public function __construct(public Message $message)
    {
        parent::__construct();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChatConfig::channel('conversation.'.$this->message->conversation_id))];
    }

    public function broadcastAs(): string
    {
        return 'message.sent';
    }

    public function broadcastWith(): array
    {
        return $this->messagePayload($this->message);
    }
}
