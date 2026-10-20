<?php

namespace Pvngu\Chat\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// The people a conversation would be started with, checked like POST conversations without starting anything
class LookupConversationRequest extends FormRequest
{
    public function rules(): array
    {
        return StoreConversationRequest::conversationRules();
    }
}
