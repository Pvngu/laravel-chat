<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\Http\Requests\StoreMessageRequest;
use Pvngu\Chat\Http\Resources\MessageResource;
use Pvngu\Chat\Http\Resources\ThreadResource;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\ThreadFollower;

// Threads: replies to a message, kept out of the chat itself
class ThreadController extends Controller
{
    // A thread's replies: page 1 holds the latest, each page lists them oldest first (like a chat's messages)
    public function replies(Request $request, Message $message): AnonymousResourceCollection
    {
        Gate::authorize('view', $message->conversation);
        $thread = $this->threadOf($message);

        $replies = $thread->replies()
            ->with(Message::presentationRelations())
            ->orderByDesc('id')
            ->simplePaginate((int) config('laravel-chat.pagination.messages', 50))
            ->withQueryString();
        $replies->setCollection($replies->getCollection()->reverse()->values());

        return MessageResource::collection($replies);
    }

    // { body, attachments[]?, gif?, replyToId? } like a message; it can quote a message of this thread
    public function reply(StoreMessageRequest $request, Message $message): JsonResponse
    {
        Gate::authorize('send', $message->conversation);
        $thread = $this->threadOf($message);

        $gif = $request->validated('gif');
        $body = (string) $request->input('body', '');
        $files = $request->file('attachments', []);
        if ($body === '' && $files === [] && $gif === null) {
            throw ValidationException::withMessages(['body' => 'The message is empty.']);
        }

        // Quoting a message of this thread: the one it hangs off, or another reply
        $replyTo = null;
        if ($request->filled('replyToId')) {
            $id = $request->input('replyToId');
            $replyTo = (string) $thread->id === (string) $id ? $thread : $thread->replies()->find($id)
                ?? throw ValidationException::withMessages(['replyToId' => 'That message isn\'t in this thread.']);
        }

        $reply = $this->chat->send($thread->conversation, $request->user(), $body, $files, $replyTo, gif: $gif, thread: $thread);

        return (new MessageResource($reply->load(Message::presentationRelations())))->response()->setStatusCode(201);
    }

    // The threads you follow, latest reply first, with how many replies you haven't seen. Deleted ones, and those of
    // chats you can't see anymore, drop out.
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $followers = (new ThreadFollower)->getTable();
        $messages = (new Message)->getTable();

        $threads = ThreadFollower::query()
            ->select("$followers.*")
            ->join($messages, "$messages.id", '=', "$followers.thread_id")
            ->where("$followers.user_id", $user->getKey())
            ->whereNull("$messages.deleted_at")
            ->where("$messages.reply_count", '>', 0)
            ->selectSub(fn (QueryBuilder $unread) => $unread
                ->from("$messages as replies")
                ->selectRaw('count(*)')
                ->whereColumn('replies.thread_id', "$followers.thread_id")
                ->whereRaw("replies.id > coalesce($followers.last_read_reply_id, 0)")
                ->whereNull('replies.deleted_at')
                ->where(fn ($query) => $query->whereNull('replies.sender_id')->orWhere('replies.sender_id', '!=', $user->getKey())), 'unread_count')
            ->with(['thread.conversation', ...array_map(fn (string $relation) => "thread.$relation", Message::presentationRelations())])
            ->orderByDesc("$messages.last_reply_at")
            ->limit((int) config('laravel-chat.pagination.threads', 50))
            ->get()
            ->filter(fn (ThreadFollower $follower) => Gate::forUser($user)->allows('view', $follower->thread->conversation))
            ->values();

        return ThreadResource::collection($threads);
    }

    // You've seen its replies so far
    public function read(Request $request, Message $message): Response
    {
        Gate::authorize('view', $message->conversation);
        $this->chat->markThreadRead($this->threadOf($message), $request->user());

        return response()->noContent();
    }

    // Threads hang off a chat's own messages: not off a reply, nor a membership line
    private function threadOf(Message $message): Message
    {
        if ($message->isReply() || $message->isEvent()) {
            throw ValidationException::withMessages(['message' => 'That message can’t have a thread.']);
        }

        return $message;
    }
}
