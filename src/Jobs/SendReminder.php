<?php

namespace Pvngu\Chat\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Pvngu\Chat\ChatManager;
use Pvngu\Chat\Models\Reminder;

// Queued by ChatManager::remind(), delayed until the reminder's time. A reminder moved later gets a job of its own, and
// this one finds it isn't due yet; one removed meanwhile is simply gone.
class SendReminder implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $reminderId) {}

    public function handle(ChatManager $chat): void
    {
        $reminder = Reminder::query()->with(['message.conversation', 'user'])->find($this->reminderId);

        if ($reminder !== null) {
            $chat->sendReminder($reminder);
        }
    }
}
