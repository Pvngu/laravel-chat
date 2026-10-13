<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Http\Resources\Concerns\PresentsPeople;
use Pvngu\Chat\Models\Attachment;
use Pvngu\Chat\Models\Mention;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\Reaction;

/**
 * A message in vue-chat's Message shape. Load Message::presentationRelations() first.
 *
 * @mixin Message
 */
class MessageResource extends JsonResource
{
    use PresentsPeople;

    public function toArray(Request $request): array
    {
        $replyTo = $this->replyTo;

        return [
            'id' => (string) $this->id,
            'senderId' => $this->personId($this->sender, $this->sender_id),
            'text' => $this->body,
            'sentAt' => $this->created_at->toIso8601ZuluString(),
            'isEdited' => $this->edited_at !== null,
            'isForwarded' => $this->forwarded_from_id !== null,
            'isPinned' => $this->pinned_at !== null,
            'reactions' => $this->reactions
                ->groupBy('emoji')
                ->map(fn ($reactions, $emoji) => [
                    'emoji' => (string) $emoji,
                    'count' => $reactions->count(),
                    'participantIds' => $reactions->map(fn (Reaction $r) => $this->personId($r->user, $r->user_id))->values(),
                ])
                ->values(),
            'attachments' => $this->attachments->map(fn (Attachment $attachment) => [
                'id' => (string) $attachment->id,
                'name' => $attachment->name,
                'size' => $attachment->size,
                'type' => (string) $attachment->mime_type,
                'url' => $attachment->url(),
                'isImage' => $attachment->isImage(),
            ])->values(),
            'gif' => $this->gif ? [
                'provider' => (string) ($this->gif['provider'] ?? ''),
                'id' => (string) ($this->gif['id'] ?? ''),
                'title' => (string) ($this->gif['title'] ?? ''),
                'width' => (int) ($this->gif['width'] ?? 0),
                'height' => (int) ($this->gif['height'] ?? 0),
                'video' => $this->gif['video'] ?? null,
                'image' => (string) ($this->gif['image'] ?? ''),
                'still' => (string) ($this->gif['still'] ?? ''),
            ] : null,
            // People mentioned with `<@id>` in the text, so it can show their current names (`<!everyone>` and `<!here>`
            // need no lookup)
            // A membership change instead of text: the sender did it to `user` (the sender too for joined and left)
            'event' => $this->event ? [
                'type' => $this->event,
                'user' => $this->person($this->eventUser, $this->event_user_id),
            ] : null,
            'mentions' => $this->personMentions
                ->map(fn (Mention $mention) => [
                    'id' => $this->personId($mention->user, $mention->user_id),
                    'name' => $this->person($mention->user, $mention->user_id)['name'],
                ])
                ->values(),
            'replyTo' => $replyTo ? [
                'messageId' => (string) $replyTo->id,
                'senderName' => $this->person($replyTo->sender, $replyTo->sender_id)['name'],
                // A deleted original's text isn't repeated in replies
                'text' => match (true) {
                    $replyTo->trashed() => '',
                    $replyTo->body === '' && $replyTo->gif !== null => 'GIF',
                    default => $replyTo->displayText(),
                },
            ] : null,
        ];
    }
}
