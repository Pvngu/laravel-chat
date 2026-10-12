<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Models\Attachment;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Serves a file through the signed, expiring link in the message's JSON (so <img> tags work without auth headers)
class AttachmentController
{
    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message()->exists(), 404); // the message was deleted

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->name);
    }
}
