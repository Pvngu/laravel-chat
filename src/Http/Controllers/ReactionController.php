<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Pvngu\Chat\Http\Requests\ReactionRequest;
use Pvngu\Chat\Http\Resources\MessageResource;
use Pvngu\Chat\Models\Message;

class ReactionController extends Controller
{
    public function store(ReactionRequest $request, Message $message): MessageResource
    {
        Gate::authorize('react', $message);
        $this->chat->react($message, $request->user(), $request->input('emoji'));

        return new MessageResource($message->load(Message::presentationRelations()));
    }

    public function destroy(Request $request, Message $message, string $emoji): MessageResource
    {
        Gate::authorize('react', $message);
        $this->chat->unreact($message, $request->user(), $emoji);

        return new MessageResource($message->load(Message::presentationRelations()));
    }
}
