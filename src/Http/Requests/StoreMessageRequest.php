<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000', 'required_without:attachments'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('laravel-chat.attachments.max_files', 12)],
            'attachments.*' => ['file', 'max:'.(int) config('laravel-chat.attachments.max_size_kb', 10240)],
            'replyToId' => ['nullable', 'string', 'regex:/^[0-9]+$/'],
            'forwardedFromId' => ['nullable', 'string', 'regex:/^[0-9]+$/'],
        ];
    }
}
