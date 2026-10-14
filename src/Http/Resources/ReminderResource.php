<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Models\Reminder;

/**
 * A reminder in vue-chat's MessageReminder shape, with the chat its message is in. Load `message.conversation` first.
 *
 * @mixin Reminder
 */
class ReminderResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $conversation = $this->message->conversation;

        return [
            'messageId' => (string) $this->message_id,
            'remindAt' => $this->remind_at->toIso8601ZuluString(),
            'chat' => [
                'kind' => $conversation->isChannel() ? 'channel' : 'conversation',
                'id' => $conversation->id,
            ],
        ];
    }
}
