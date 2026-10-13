<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Pvngu\Chat\Contracts\ChatPresence;

class PresenceRequest extends FormRequest
{
    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'in:'.ChatPresence::ONLINE.','.ChatPresence::AWAY],
        ];
    }
}
