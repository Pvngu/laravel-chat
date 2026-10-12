<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ReactionRequest extends FormRequest
{
    public function rules(): array
    {
        return ['emoji' => ['required', 'string', 'max:32']];
    }
}
