<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pvngu\Chat\Http\Requests\Concerns\MessageContentRules;
use Pvngu\Chat\Support\Features;

class StoreConversationRequest extends FormRequest
{
    use MessageContentRules;

    /** Who's in it: shared with the lookup (LookupConversationRequest). @return array<string, list<mixed>> */
    public static function conversationRules(): array
    {
        // Without groups (laravel-chat.features), only direct messages
        $groups = Features::enabled('groups');

        return [
            'participantIds' => ['required', 'array', 'min:1', $groups ? 'max:100' : 'max:1'],
            'participantIds.*' => ['required', 'string'],
            'isDirect' => $groups ? ['sometimes', 'boolean'] : ['accepted'],
            'name' => [...$groups ? [] : ['prohibited'], 'nullable', 'string', 'max:120'],
        ];
    }

    public function rules(): array
    {
        return [
            ...static::conversationRules(),
            // Optional: its first message, saved with it (ChatManager::start)
            ...$this->messageContentRules(),
            'replyToId' => ['prohibited'],
            'forwardedFromId' => ['prohibited'],
            'alsoSendToChat' => ['prohibited'],
        ];
    }

    // A first message comes with it. An empty one is refused rather than starting the conversation without it.
    public function hasFirstMessage(): bool
    {
        return $this->hasAny(['body', 'attachments', 'gif']);
    }
}
