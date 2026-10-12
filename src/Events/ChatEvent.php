<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pvngu\Chat\Http\Resources\MessageResource;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Support\ChatConfig;

/**
 * Chat events are ordinary Laravel events (listen to them, e.g. to send notifications) that are also broadcast on
 * private channels when config('laravel-chat.broadcasting.enabled') is on. Inside a database transaction they wait for
 * the commit, so nobody hears about a message that was rolled back.
 */
abstract class ChatEvent implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ?string $connection = null;

    public function __construct()
    {
        $this->connection = config('laravel-chat.broadcasting.connection');
        // The sender's own browser already shows the change (when it sends its X-Socket-ID)
        $this->dontBroadcastToCurrentUser();
    }

    public function broadcastWhen(): bool
    {
        return ChatConfig::broadcasts();
    }

    public function broadcastQueue(): ?string
    {
        return config('laravel-chat.broadcasting.queue');
    }

    /** @return array{kind: string, conversationId: int, message: array<string, mixed>} */
    protected function messagePayload(Message $message): array
    {
        $message->loadMissing([...Message::presentationRelations(), 'conversation']);

        return [
            'kind' => $message->conversation->isChannel() ? 'channel' : 'conversation',
            'conversationId' => $message->conversation_id,
            'message' => (new MessageResource($message))->resolve(),
        ];
    }
}
