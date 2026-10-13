<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Http\Resources\Concerns\PresentsChatDetails;
use Pvngu\Chat\Http\Resources\Concerns\PresentsPeople;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Participant;

/**
 * A direct or group conversation in vue-chat's Conversation shape, as the viewer sees it.
 * Query it withViewerState() and load participants.user and latestMessage.sender.
 *
 * @mixin Conversation
 */
class ConversationResource extends JsonResource
{
    use PresentsChatDetails, PresentsPeople;

    public function toArray(Request $request): array
    {
        $latest = $this->latestMessage;

        return [
            'id' => $this->id,
            'isDirect' => $this->isDirect(),
            'isPrivate' => $this->is_private,
            'title' => $this->isDirect() ? null : $this->name,
            'description' => $this->description,
            // With their role in a group (admins manage it); a direct message has none
            'participants' => $this->participants
                ->map(fn (Participant $participant) => [
                    ...$this->person($participant->user, $participant->user_id),
                    ...($this->isDirect() ? [] : ['role' => $participant->role]),
                ])
                ->values(),
            'lastMessage' => $latest ? [
                'text' => $latest->previewText(),
                'sentAt' => $latest->created_at->toIso8601ZuluString(),
                'sender' => $this->person($latest->sender, $latest->sender_id),
            ] : null,
            'unreadCount' => (int) ($this->unread_count ?? 0),
            'isPinned' => $this->viewer_pinned_at !== null,
            'isFavorite' => $this->viewer_favorited_at !== null,
            ...$this->chatDetails(),
        ];
    }
}
