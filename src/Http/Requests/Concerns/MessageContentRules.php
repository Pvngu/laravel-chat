<?php

namespace Pvngu\Chat\Http\Requests\Concerns;

use Closure;
use Pvngu\Chat\Support\Features;

// What a message can hold, for the requests that send one (a message, or a conversation started with its first one)
trait MessageContentRules
{
    /** What a message can hold: text, files, a GIF. @return array<string, list<mixed>> */
    protected function messageContentRules(): array
    {
        return [
            'body' => ['nullable', 'string', 'max:10000'],
            'attachments' => [...$this->unlessOff('attachments'), 'sometimes', 'array', 'max:'.(int) config('laravel-chat.attachments.max_files', 12)],
            'attachments.*' => ['file', 'max:'.(int) config('laravel-chat.attachments.max_size_kb', 10240)],

            // A GIF picked from a provider such as KLIPY; its media must come from the configured hosts
            'gif' => [...$this->unlessOff('gifs'), 'nullable', 'array'],
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

    // Refused when its feature is off (laravel-chat.features)
    /** @return list<string> */
    private function unlessOff(string $feature): array
    {
        return Features::enabled($feature) ? [] : ['prohibited'];
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
