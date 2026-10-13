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
    // A chat's files, newest first: `type=media` for photos and videos, `type=files` for the rest. `before` is the id
    // of the last one shown, so files sent meanwhile don't shift the next page.
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);
        $request->validate([
            'type' => ['nullable', 'in:media,files'],
            'before' => ['nullable', 'string', 'regex:/^[0-9]+$/'],
        ]);

        $attachments = (new Attachment)->getTable();
        $messages = (new Message)->getTable();
        $isMedia = fn (Builder $query) => $query
            ->where("$attachments.mime_type", 'like', 'image/%')
            ->orWhere("$attachments.mime_type", 'like', 'video/%');

        $files = Attachment::query()
            ->select("$attachments.*")
            // Files of deleted messages are gone with them
            ->join($messages, "$messages.id", '=', "$attachments.message_id")
            ->where("$messages.conversation_id", $conversation->id)
            ->whereNull("$messages.deleted_at")
            ->when($request->input('type') === 'media', fn (Builder $query) => $query->where($isMedia))
            ->when($request->input('type') === 'files', fn (Builder $query) => $query->where(
                fn (Builder $query) => $query->whereNull("$attachments.mime_type")->orWhereNot($isMedia),
            ))
            ->when($request->filled('before'), fn (Builder $query) => $query->where("$attachments.id", '<', $request->input('before')))
            ->with('message.sender')
            ->orderByDesc("$attachments.id")
            ->simplePaginate((int) config('laravel-chat.pagination.files', 30))
            ->withQueryString();

        return SharedFileResource::collection($files);
    }

    // Serves a file through the signed, expiring link in the message's JSON (so <img> tags work without auth headers)
    public function show(Attachment $attachment): StreamedResponse
    {
        abort_unless($attachment->message()->exists(), 404); // the message was deleted

        return Storage::disk($attachment->disk)->response($attachment->path, $attachment->name);
    }
}
