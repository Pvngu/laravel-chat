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
    protected string $baseTable = 'attachments';

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }

    public function isImage(): bool
    {
        return str_starts_with((string) $this->mime_type, 'image/');
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
