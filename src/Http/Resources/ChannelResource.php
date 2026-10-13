<?php

namespace Pvngu\Chat\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Pvngu\Chat\Http\Resources\Concerns\PresentsChatDetails;
use Pvngu\Chat\Http\Resources\Concerns\PresentsPeople;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Participant;

/**
 * A channel in vue-chat's Channel shape, as the viewer sees it. Query it withViewerState() and load participants.user.
 *
 * @mixin Conversation
 */
class ChannelResource extends JsonResource
{
    use PresentsChatDetails, PresentsPeople;

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => (string) $this->name,
            'isPrivate' => $this->is_private,
            'description' => $this->description,
            // With their role: admins manage the chat
            'members' => $this->participants
                ->map(fn (Participant $participant) => [
                    ...$this->person($participant->user, $participant->user_id),
                    'role' => $participant->role,
                ])
                ->values(),
            'unreadCount' => (int) ($this->unread_count ?? 0),
            'isMember' => $this->viewer_participant_id !== null,
            'isFavorite' => $this->viewer_favorited_at !== null,
            ...$this->chatDetails(),
        ];
    }
}
