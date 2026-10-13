<?php

namespace Pvngu\Chat\Http\Resources\Concerns;

use Illuminate\Support\Carbon;

/**
 * The viewer's own settings for a conversation or channel, and who created it when. Reads the columns that
 * Conversation::scopeWithViewerState() adds; needs PresentsPeople.
 */
trait PresentsChatDetails
{
    protected function chatDetails(): array
    {
        $mutedUntil = $this->viewer_muted_until ? Carbon::parse($this->viewer_muted_until) : null;

        return [
            'notifications' => $this->viewer_notifications ?? 'all',
            'mutedUntil' => $mutedUntil?->isFuture() ? $mutedUntil->toIso8601ZuluString() : null,
            'mentionCount' => (int) ($this->mention_count ?? 0),
            'isAdmin' => $this->viewer_role === 'admin',
            'createdAt' => $this->created_at?->toIso8601ZuluString(),
            'createdBy' => $this->created_by !== null ? $this->person($this->creator, $this->created_by) : null,
        ];
    }
}
