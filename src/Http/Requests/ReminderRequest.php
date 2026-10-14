<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReminderRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'remindAt' => ['required', 'date', 'after:now', 'before:+1 year'],
        ];
    }

    public function messages(): array
    {
        return [
            'remindAt.after' => 'Pick a time that hasn’t passed yet.',
            'remindAt.before' => 'Pick a time within the next year.',
        ];
    }
}
