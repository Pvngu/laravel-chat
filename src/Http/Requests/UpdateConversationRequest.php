<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\ConversationType;
use Pvngu\Chat\Support\ChatConfig;

class UpdateConversationRequest extends FormRequest
{
    public function rules(): array
    {
        /** @var Conversation $conversation */
        $conversation = $this->route('conversation');

        return [
            // A channel needs a name that's unique among the tenant's channels; a group's is optional
            'name' => $conversation->isChannel()
                ? [
                    'sometimes', 'required', 'string', 'max:80',
                    Rule::unique(ChatConfig::table('conversations'), 'name')
                        ->where('type', ConversationType::Channel->value)
                        ->where('tenant', $conversation->tenant)
                        ->ignore($conversation->id),
                ]
                : ['sometimes', 'nullable', 'string', 'max:80'],
            'description' => ['sometimes', 'nullable', 'string', 'max:500'],
            'isPrivate' => ['sometimes', 'boolean'],
        ];
    }
}
