<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\Http\Requests\StoreMessageRequest;
use Pvngu\Chat\Http\Requests\UpdateMessageRequest;
use Pvngu\Chat\Http\Resources\MessageResource;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;

class MessageController extends Controller
{
    // Page 1 holds the latest messages, page 2 the ones before, and so on. Each page lists its messages oldest first,
    // so messages sent within the same second (timestamps have one-second precision) keep their order
    public function index(Request $request, Conversation $conversation): AnonymousResourceCollection
    {
        Gate::authorize('view', $conversation);

        $messages = $conversation->messages()
            ->inChat() // replies are in their threads, unless they were sent to the chat too
            ->with(Message::presentationRelations())
            ->orderByDesc('id')
            ->simplePaginate((int) config('laravel-chat.pagination.messages', 50))
            ->withQueryString();
        $messages->setCollection($messages->getCollection()->reverse()->values());

        return MessageResource::collection($messages);
    }

    public function store(StoreMessageRequest $request, Conversation $conversation)
    {
        Gate::authorize('send', $conversation);

        $replyTo = $request->filled('replyToId')
            ? $conversation->messages()->whereNull('event')->find($request->input('replyToId'))
                ?? throw ValidationException::withMessages(['replyToId' => 'That message isn\'t in this conversation.'])
            : null;

        $forwardedFrom = null;
        if ($request->filled('forwardedFromId')) {
            $forwardedFrom = Message::query()->whereNull('event')->find($request->input('forwardedFromId'));
            if (! $forwardedFrom || Gate::denies('view', $forwardedFrom->conversation)) {
                throw ValidationException::withMessages(['forwardedFromId' => 'That message can\'t be forwarded.']);
            }
        }

        // A forwarded GIF comes along with its message
        $gif = $request->validated('gif') ?? $forwardedFrom?->gif;
        $body = (string) $request->input('body', '');
        $files = $request->file('attachments', []);
        if ($body === '' && $files === [] && $gif === null) {
            throw ValidationException::withMessages(['body' => 'The message is empty.']);
        }

        $message = $this->chat->send($conversation, $request->user(), $body, $files, $replyTo, $forwardedFrom, $gif);

        return (new MessageResource($message->load(Message::presentationRelations())))->response()->setStatusCode(201);
    }

    public function update(UpdateMessageRequest $request, Message $message): MessageResource
    {
        Gate::authorize('update', $message);
        $this->chat->edit($message, $request->input('body'));

        return new MessageResource($message->load(Message::presentationRelations()));
    }

    public function destroy(Message $message): Response
    {
        Gate::authorize('delete', $message);
        $this->chat->delete($message);

        return response()->noContent();
    }

    public function pin(Request $request, Message $message): MessageResource
    {
        Gate::authorize('pin', $message);
        $this->chat->pin($message, $request->boolean('pinned', true));

        return new MessageResource($message->load(Message::presentationRelations()));
    }
}
