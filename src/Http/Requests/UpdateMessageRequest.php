<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:10000']];
    }
}
