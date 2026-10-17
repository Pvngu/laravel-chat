<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Pvngu\Chat\Casts\UtcDatetime;
use Pvngu\Chat\Support\ChatConfig;

/**
 * Someone asked to be reminded of a message at remind_at. sent_at is set once ReminderDue has fired.
 *
 * @property int $id
 * @property int $message_id
 * @property int|string $user_id
 * @property Carbon $remind_at
 * @property Carbon|null $sent_at
 */
class Reminder extends ChatModel
{
    protected string $baseTable = 'reminders';

    protected $casts = [
        'remind_at' => UtcDatetime::class, // UTC in the database
        'sent_at' => 'datetime',
    ];

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class)->withTrashed();
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
