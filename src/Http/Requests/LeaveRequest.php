<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeaveRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // Who becomes admin when the last admin leaves (otherwise whoever has been in it longest)
        return ['successorId' => ['nullable', 'string']];
    }
}
