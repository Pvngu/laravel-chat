<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Http\Resources\SharedFileResource;
use Pvngu\Chat\Models\Attachment;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController
{
    // A chat's files, newest first: `type=media` for the photos and videos the chat shows (Attachment::INLINE_TYPES),
    // `type=files` for the rest. `before` is the id of the last one shown, so files sent meanwhile don't shift the
    // next page.
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);
        $request->validate([
            'type' => ['nullable', 'in:media,files'],
            'before' => ['nullable', 'string', 'regex:/^[0-9]+$/'],
        ]);

        $attachments = (new Attachment)->getTable();
        $messages = (new Message)->getTable();
        $media = array_values(array_filter(
            Attachment::INLINE_TYPES,
            fn (string $type) => str_starts_with($type, 'image/') || str_starts_with($type, 'video/'),
        ));

        $files = Attachment::query()
            ->select("$attachments.*")
            // Files of deleted messages are gone with them
            ->join($messages, "$messages.id", '=', "$attachments.message_id")
            ->where("$messages.conversation_id", $conversation->id)
            ->whereNull("$messages.deleted_at")
            ->when($request->input('type') === 'media', fn (Builder $query) => $query->whereIn("$attachments.mime_type", $media))
            ->when($request->input('type') === 'files', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNull("$attachments.mime_type")->orWhereNotIn("$attachments.mime_type", $media),
            ))
            ->when($request->filled('before'), fn (Builder $query) => $query->where("$attachments.id", '<', $request->input('before')))
            ->with('message.sender')
            ->orderByDesc("$attachments.id")
            ->simplePaginate((int) config('laravel-chat.pagination.files', 30))
            ->withQueryString();

        return SharedFileResource::collection($files);
    }

    /**
     * Serves a file through the signed, expiring link in the message's JSON (so <img> tags work without auth headers).
     * The link is on the app's own site, so only plain images, video and audio show in the browser; anything else
     * (HTML, SVG, PDF…) downloads, its type never guessed from its content, and is sandboxed should it render anyway.
     */
    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message()->exists(), 404); // the message was deleted

        $inline = $attachment->isInlineSafe();

        return Storage::disk($attachment->disk)->response(
            $attachment->path,
            $attachment->name,
            [
                'Content-Type' => $inline ? strtolower((string) $attachment->mime_type) : 'application/octet-stream',
                'X-Content-Type-Options' => 'nosniff',
                'Content-Security-Policy' => "default-src 'none'; img-src 'self'; media-src 'self'; style-src 'unsafe-inline'; sandbox",
                'Cache-Control' => 'private, max-age=3600',
            ],
            $inline ? 'inline' : 'attachment',
        );
    }
}
