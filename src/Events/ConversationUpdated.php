<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Support\ChatConfig;

// A conversation's or channel's name, description or privacy changed (only what's the same for everyone in it)
class ConversationUpdated extends ChatEvent
{
    public function __construct(public Conversation $conversation)
    {
        parent::__construct();
    }

    public function broadcastOn(): array
    {
        return [new PrivateChannel(ChatConfig::channel('conversation.'.$this->conversation->id))];
    }

    public function broadcastAs(): string
    {
        return 'conversation.updated';
    }

    public function broadcastWith(): array
    {
        return [
            'kind' => $this->conversation->isChannel() ? 'channel' : 'conversation',
            'conversationId' => $this->conversation->id,
            'name' => $this->conversation->name,
            'description' => $this->conversation->description,
            'isPrivate' => (bool) $this->conversation->is_private,
        ];
    }
}
