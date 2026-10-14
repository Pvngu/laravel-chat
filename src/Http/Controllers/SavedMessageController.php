<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\Http\Resources\SavedMessageResource;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\SavedMessage;

// Messages you saved for later
class SavedMessageController extends Controller
{
    // Newest first; deleted messages, and those of chats you can't see anymore, are left out
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $saved = SavedMessage::query()
            ->where('user_id', $user->getKey())
            ->whereHas('message.conversation', fn (Builder $conversation) => $conversation->where(
                fn (Builder $query) => $query
                    ->whereHas('participants', fn (Builder $participant) => $participant->where('user_id', $user->getKey()))
                    ->orWhere(fn (Builder $public) => $public->where('type', 'channel')->where('is_private', false)),
            ))
            ->with(['message.conversation', ...array_map(fn (string $relation) => "message.$relation", Message::presentationRelations())])
            ->latest()
            ->latest('id')
            ->limit((int) config('laravel-chat.pagination.saved', 200))
            ->get();

        return SavedMessageResource::collection($saved);
    }

    public function update(Request $request, Message $message): Response
    {
        Gate::authorize('view', $message->conversation);
        if ($message->isEvent()) {
            throw ValidationException::withMessages(['message' => 'That message can’t be saved.']);
        }

        $saved = $this->chat->saveMessage($request->user(), $message);

        return response()->noContent($saved->wasRecentlyCreated ? 201 : 204);
    }

    public function destroy(Request $request, Message $message): Response
    {
        $this->chat->unsaveMessage($request->user(), $message);

        return response()->noContent();
    }
}
