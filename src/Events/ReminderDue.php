<?php

namespace Pvngu\Chat\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Pvngu\Chat\Models\Reminder;

/**
 * It's time for a reminder someone set on a message. Listen for it to tell them: a notification, an email, a push.
 * The message still exists and they can still see its chat.
 */
class ReminderDue
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public Reminder $reminder) {}
}
