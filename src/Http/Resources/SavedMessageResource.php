<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Models\SavedMessage;

/**
 * A saved message: the message as chats show it, which chat it's in, and when it was saved. Load
 * `message.conversation` and Message::presentationRelations() under `message` first.
 *
 * @mixin SavedMessage
 */
class SavedMessageResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $conversation = $this->message->conversation;

        return [
            'message' => new MessageResource($this->message),
            'chat' => [
                'kind' => $conversation->isChannel() ? 'channel' : 'conversation',
                'id' => $conversation->id,
                // The channel's or group's name; vue-chat names direct messages after the other person
                'name' => $conversation->name,
                'isDirect' => $conversation->isDirect(),
            ],
            'savedAt' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
