<?php

namespace Pvngu\Chat\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;

class StoreMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000', 'required_without_all:attachments,gif,forwardedFromId'],
            'attachments' => ['sometimes', 'array', 'max:'.(int) config('laravel-chat.attachments.max_files', 12)],
            'attachments.*' => ['file', 'max:'.(int) config('laravel-chat.attachments.max_size_kb', 10240)],
            'replyToId' => ['nullable', 'string', 'regex:/^[0-9]+$/'],
            'forwardedFromId' => ['nullable', 'string', 'regex:/^[0-9]+$/'],
            // A reply in a thread that shows in the chat too
            'alsoSendToChat' => ['sometimes', 'boolean'],

            // A GIF picked from a provider such as KLIPY; its media must come from the configured hosts
            'gif' => ['nullable', 'array'],
            'gif.provider' => ['required_with:gif', 'string', 'max:32'],
            'gif.id' => ['required_with:gif', 'string', 'max:191'],
            'gif.title' => ['nullable', 'string', 'max:255'],
            'gif.width' => ['required_with:gif', 'integer', 'between:1,4096'],
            'gif.height' => ['required_with:gif', 'integer', 'between:1,4096'],
            'gif.video' => ['nullable', 'string', 'max:2048', $this->gifMedia(...)],
            'gif.image' => ['required_with:gif', 'string', 'max:2048', $this->gifMedia(...)],
            'gif.still' => ['required_with:gif', 'string', 'max:2048', $this->gifMedia(...)],
        ];
    }

    // An HTTPS URL on one of laravel-chat.gifs.media_hosts, so messages can't embed images from anywhere else
    private function gifMedia(string $attribute, mixed $value, Closure $fail): void
    {
        $hosts = array_map(strtolower(...), (array) config('laravel-chat.gifs.media_hosts', []));
        $url = is_string($value) ? parse_url($value) : false;

        if (! $url || ($url['scheme'] ?? null) !== 'https' || ! in_array(strtolower($url['host'] ?? ''), $hosts, true)) {
            $fail('GIFs can only come from the configured GIF provider.');
        }
    }
}
