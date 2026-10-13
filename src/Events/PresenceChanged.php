<?php

namespace Pvngu\Chat\Events;

use Illuminate\Broadcasting\PresenceChannel;

// Someone went away or came back, told to everyone on their tenant's presence channel (joining and leaving it is
// online and offline)
class PresenceChanged extends ChatEvent
{
    public function __construct(
        public string $channel,
        public string $userId,
        public string $status,
    ) {
        parent::__construct();
    }

    public function broadcastOn(): array
    {
        return [new PresenceChannel($this->channel)];
    }

    public function broadcastAs(): string
    {
        return 'presence.changed';
    }

    /** @return array{userId: string, status: string} */
    public function broadcastWith(): array
    {
        return ['userId' => $this->userId, 'status' => $this->status];
    }
}
