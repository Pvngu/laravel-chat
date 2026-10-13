<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pvngu\Chat\Models\Participant;

class MemberRoleRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['role' => ['required', 'in:'.Participant::ADMIN.','.Participant::MEMBER]];
    }
}
