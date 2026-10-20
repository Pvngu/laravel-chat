<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pvngu\Chat\Http\Requests\Concerns\MessageContentRules;

class StoreMessageRequest extends FormRequest
{
    use MessageContentRules;

    public function rules(): array
    {
        $rules = $this->messageContentRules();
        $rules['body'][] = 'required_without_all:attachments,gif,forwardedFromId';

        return [
            ...$rules,
            'replyToId' => [...$this->unlessOff('replies'), 'nullable', 'string', 'regex:/^[0-9]+$/'],
            'forwardedFromId' => [...$this->unlessOff('forwarding'), 'nullable', 'string', 'regex:/^[0-9]+$/'],
            // A reply in a thread that shows in the chat too
            'alsoSendToChat' => ['sometimes', 'boolean'],
        ];
    }
}
