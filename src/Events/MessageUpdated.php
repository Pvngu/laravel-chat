<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Support\ChatConfig;

// Edited, pinned or unpinned, or its reactions changed: the payload is the whole message
class MessageUpdated extends ChatEvent
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
        return 'message.updated';
    }

    public function broadcastWith(): array
    {
        return $this->messagePayload($this->message);
    }
}
