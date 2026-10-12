<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MemberRequest extends FormRequest
{
    public function rules(): array
    {
        return ['userId' => ['required', 'string']];
    }
}
