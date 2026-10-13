<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'level' => ['required', Rule::in(['all', 'mentions', 'none'])],
            // A temporary mute; null (or leaving it out) unmutes
            'mutedUntil' => ['nullable', 'date', 'after:now'],
        ];
    }
}
