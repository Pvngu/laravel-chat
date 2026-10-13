<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Pvngu\Chat\Support\ChatConfig;

/**
 * Someone a message mentions: by name (`<@id>` in its text), or through `<!everyone>` or `<!here>`.
 *
 * @property int $message_id
 * @property int $conversation_id
 * @property int|string $user_id
 * @property string $via
 */
class Mention extends ChatModel
{
    public const VIA_PERSON = 'person';

    public const VIA_EVERYONE = 'everyone';

    public const VIA_HERE = 'here';

    public $timestamps = false;

    protected string $baseTable = 'mentions';

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    /** @return BelongsTo<Model, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(ChatConfig::userModel(), 'user_id');
    }
}
