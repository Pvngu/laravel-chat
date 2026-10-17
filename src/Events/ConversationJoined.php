<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Http\Resources\ChannelResource;
use Pvngu\Chat\Http\Resources\ConversationResource;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Support\ChatConfig;

// Someone was added to a conversation or channel (or one was started with them), or a new message brought back a
// conversation they had archived: it appears in their sidebar
class ConversationJoined extends ChatEvent
{
    public function __construct(public Conversation $conversation, public Model $user)
    {
        parent::__construct();
    }

    public function broadcastOn(): array
    {
        $publicId = app(ChatUserProvider::class)->publicId($this->user);

        return [new PrivateChannel(ChatConfig::channel('user.'.$publicId))];
    }

    public function broadcastAs(): string
    {
        return 'conversation.joined';
    }

    public function broadcastWith(): array
    {
        // Rendered as the joining user sees it (their unread count, the other person in a direct message)
        $conversation = Conversation::query()
            ->withViewerState($this->user)
            ->with(Conversation::presentationRelations())
            ->findOrFail($this->conversation->id);

        $resource = $conversation->isChannel()
            ? new ChannelResource($conversation)
            : new ConversationResource($conversation);

        return [
            'kind' => $conversation->isChannel() ? 'channel' : 'conversation',
            'conversation' => $resource->resolve(),
        ];
    }
}
