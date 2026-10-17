<?php

namespace Pvngu\Chat\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * @property int $id
 * @property string $disk
 * @property string $path
 * @property string $name
 * @property string|null $mime_type
 * @property int $size
 */
class Attachment extends ChatModel
{
    /**
     * Types the browser may show in the page or in its own tab: plain images, video and audio, which can't run
     * script. Anything else (HTML, SVG, XML, PDF…) is only ever downloaded, so a file someone uploads can't act as a
     * page of the app's own site.
     */
    public const INLINE_TYPES = [
        'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'image/avif', 'image/bmp',
        'video/mp4', 'video/webm', 'video/quicktime', 'video/ogg',
        'audio/mpeg', 'audio/ogg', 'audio/wav', 'audio/webm', 'audio/mp4',
    ];

    protected string $baseTable = 'attachments';

    /** @return BelongsTo<Message, $this> */
    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isInlineSafe(): bool
    {
        return in_array(strtolower((string) $this->mime_type), self::INLINE_TYPES, true);
    }

    // An image the chat shows as one (an SVG is a file: it can carry script)
    public function isImage(): bool
    {
        return $this->isInlineSafe() && str_starts_with(strtolower((string) $this->mime_type), 'image/');
    }

    // A link that expires: the disk's own temporary URL where it has one (S3, …), otherwise a signed route
    public function url(): string
    {
        $expires = now()->addMinutes((int) config('laravel-chat.attachments.url_ttl_minutes', 60));
        $disk = Storage::disk($this->disk);

        if ($disk->providesTemporaryUrls()) {
            return $disk->temporaryUrl($this->path, $expires);
        }

        return URL::temporarySignedRoute(
            config('laravel-chat.routes.name', 'chat.').'attachments.show',
            $expires,
            ['attachment' => $this->id],
        );
    }
}
