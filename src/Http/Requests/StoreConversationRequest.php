<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'participantIds' => ['required', 'array', 'min:1', 'max:100'],
            'participantIds.*' => ['required', 'string'],
            'isDirect' => ['sometimes', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
