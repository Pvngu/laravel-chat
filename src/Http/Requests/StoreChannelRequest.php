<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Models\ConversationType;
use Pvngu\Chat\Support\ChatConfig;

class StoreChannelRequest extends FormRequest
{
    public function rules(): array
    {
        $tenant = app(ChatUserProvider::class)->tenant($this->user());

        return [
            // Unique among the tenant's channels
            'name' => [
                'required', 'string', 'max:80',
                Rule::unique(ChatConfig::table('conversations'), 'name')
                    ->where('type', ConversationType::Channel->value)
                    ->where('tenant', $tenant),
            ],
            'isPrivate' => ['sometimes', 'boolean'],
            'description' => ['nullable', 'string', 'max:500'],
        ];
    }
}
