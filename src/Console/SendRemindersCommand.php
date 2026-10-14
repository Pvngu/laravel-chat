<?php

namespace Pvngu\Chat\Console;

use Illuminate\Console\Command;
use Pvngu\Chat\ChatManager;

// Reminders are sent by delayed queue jobs; scheduling this as well catches any the queue missed (a cleared queue, a
// driver that can't delay that long, like SQS past 15 minutes)
class SendRemindersCommand extends Command
{
    protected $signature = 'chat:send-reminders';

    protected $description = 'Send the chat reminders that are due';

    public function handle(ChatManager $chat): int
    {
        $sent = $chat->sendDueReminders();
        $this->components->info($sent === 1 ? 'Sent 1 reminder.' : "Sent $sent reminders.");

        return self::SUCCESS;
    }
}
