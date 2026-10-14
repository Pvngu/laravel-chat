<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pvngu\Chat\Support\Features;

class StoreConversationRequest extends FormRequest
{
    public function rules(): array
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
}
