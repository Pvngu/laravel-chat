<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Models\ThreadFollower;

/**
 * A thread you follow: the message it hangs off (with replyCount and lastReplyAt), its chat, and how many replies you
 * haven't seen. Load `thread.conversation` and Message::presentationRelations() under `thread` first.
 *
 * @mixin ThreadFollower
 */
class ThreadResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $conversation = $this->thread->conversation;

        return [
            'message' => new MessageResource($this->thread),
            'chat' => [
                'kind' => $conversation->isChannel() ? 'channel' : 'conversation',
                'id' => $conversation->id,
                'name' => $conversation->name,
                'isDirect' => $conversation->isDirect(),
            ],
            'unreadCount' => (int) ($this->unread_count ?? 0),
            // Its latest replies (up to three), oldest first
            'latestReplies' => MessageResource::collection($this->thread->latestReplies->reverse()->values()),
        ];
    }
}
