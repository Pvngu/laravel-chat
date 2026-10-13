<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Http\Resources\Concerns\PresentsPeople;
use Pvngu\Chat\Models\Attachment;

/**
 * A file shared in a chat, for its Files tab: the attachment as messages show it, plus which message it came with,
 * who sent it and when. Load `message.sender` first.
 *
 * @mixin Attachment
 */
class SharedFileResource extends JsonResource
{
    use PresentsPeople;

    public function toArray(Request $request): array
    {
        $message = $this->message;

        return [
            'id' => (string) $this->id,
            'name' => $this->name,
            'size' => $this->size,
            'type' => (string) $this->mime_type,
            'url' => $this->url(),
            'isImage' => $this->isImage(),
            'messageId' => (string) $this->message_id,
            'sender' => $this->person($message->sender, $message->sender_id),
            'sentAt' => $message->created_at->toIso8601ZuluString(),
        ];
    }
}
