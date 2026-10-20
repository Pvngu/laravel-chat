<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\Http\Requests\LookupConversationRequest;
use Pvngu\Chat\Http\Requests\NotificationsRequest;
use Pvngu\Chat\Http\Requests\StoreConversationRequest;
use Pvngu\Chat\Http\Requests\UpdateConversationRequest;
use Pvngu\Chat\Http\Resources\ChannelResource;
use Pvngu\Chat\Http\Resources\ConversationResource;
use Pvngu\Chat\Http\Resources\MessageResource;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;

class ConversationController extends Controller
{
    // The viewer's direct and group conversations, latest activity first (archived ones left out)
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $table = (new Conversation)->getTable();

        $conversations = Conversation::query()
            ->conversationsOf($user, $this->users->tenant($user))
            ->whereNull('viewer.archived_at')
            ->with(Conversation::presentationRelations())
            ->orderByRaw("coalesce($table.last_message_at, $table.created_at) desc")
            ->orderByDesc("$table.id")
            ->simplePaginate((int) config('laravel-chat.pagination.conversations', 30))
            ->withQueryString();

        return ConversationResource::collection($conversations);
    }

    // A direct message (the existing one when there is one) or a group conversation. With a first message (body,
    // attachments or gif) both are saved together.
    public function store(StoreConversationRequest $request)
    {
        $user = $request->user();
        $people = $this->people($request, $user);
        $direct = $request->boolean('isDirect') && $people->count() === 1;
        if ($request->hasFirstMessage()) {
            return $this->startWithMessage($request, $user, $people, $direct);
        }

        $conversation = $direct
            ? $this->chat->direct($user, $people->first())
            : $this->chat->group($user, $people, $request->input('name'));
        // A direct message they already had, maybe archived: starting it again brings it back to their sidebar
        if (! $conversation->wasRecentlyCreated) {
            $this->chat->setFlag($conversation, $user, 'archived', false);
        }

        return (new ConversationResource($this->forViewer($user, $conversation->id)))
            ->response()
            ->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
    }

    // What these people already have: their direct message (archived too) as you see it, or data: null. Changes nothing.
    public function lookup(LookupConversationRequest $request): JsonResponse
    {
        $user = $request->user();
        $people = $this->people($request, $user);
        $conversation = $request->boolean('isDirect') && $people->count() === 1
            ? $this->chat->findDirect($user, $people->first())
            : null; // a group is new every time
        if (! $conversation) {
            return response()->json(['data' => null]);
        }
        Gate::authorize('view', $conversation);

        return (new ConversationResource($this->forViewer($user, $conversation->id)))->response();
    }

    public function read(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('participate', $conversation);
        $this->chat->markRead($conversation, $request->user());

        return response()->noContent();
    }

    public function pin(Request $request, Conversation $conversation): Response
    {
        return $this->flag($request, $conversation, 'pinned', $request->boolean('pinned', true));
    }

    public function favorite(Request $request, Conversation $conversation): Response
    {
        return $this->flag($request, $conversation, 'favorited', $request->boolean('favorite', true));
    }

    public function archive(Request $request, Conversation $conversation): Response
    {
        return $this->flag($request, $conversation, 'archived', $request->boolean('archived', true));
    }

    public function update(UpdateConversationRequest $request, Conversation $conversation): ConversationResource|ChannelResource
    {
        Gate::authorize('update', $conversation);
        $this->chat->update($conversation, $request->validated());

        return $this->show($request, $conversation);
    }

    public function notifications(NotificationsRequest $request, Conversation $conversation): Response
    {
        Gate::authorize('participate', $conversation);
        $mutedUntil = $request->validated('mutedUntil');
        $this->chat->setNotifications(
            $conversation,
            $request->user(),
            $request->validated('level'),
            $mutedUntil ? Carbon::parse($mutedUntil) : null,
        );

        return response()->noContent();
    }

    public function show(Request $request, Conversation $conversation): ConversationResource|ChannelResource
    {
        Gate::authorize('view', $conversation);
        $conversation = $this->forViewer($request->user(), $conversation->id);

        return $conversation->isChannel() ? new ChannelResource($conversation) : new ConversationResource($conversation);
    }

    private function flag(Request $request, Conversation $conversation, string $flag, bool $on): Response
    {
        Gate::authorize('participate', $conversation);
        $this->chat->setFlag($conversation, $request->user(), $flag, $on);

        return response()->noContent();
    }

    /** @param Collection<int, Model> $people */
    private function startWithMessage(StoreConversationRequest $request, Model $user, Collection $people, bool $direct): JsonResponse
    {
        $body = (string) $request->input('body', '');
        $files = $request->file('attachments', []);
        $gif = $request->validated('gif');
        if ($body === '' && $files === [] && $gif === null) {
            throw ValidationException::withMessages(['body' => 'The message is empty.']);
        }

        [$conversation, $message] = $this->chat->start(
            $user,
            $people,
            $direct,
            $request->input('name'),
            $body,
            $files,
            $gif,
            // Your policy decides (host overrides too), before anything is stored
            authorize: fn (Conversation $conversation) => Gate::authorize('send', $conversation),
        );

        return (new ConversationResource($this->forViewer($user, $conversation->id)))
            ->additional([
                'firstMessage' => (new MessageResource($message->load(Message::presentationRelations())))->resolve($request),
                'created' => $conversation->wasRecentlyCreated,
            ])
            ->response()
            ->setStatusCode($conversation->wasRecentlyCreated ? 201 : 200);
    }

    // The people picked, each one someone you may message (or a 422 on participantIds)
    /** @return Collection<int, Model> */
    private function people(Request $request, Model $user): Collection
    {
        return collect($request->input('participantIds'))
            ->unique()
            ->values()
            ->map(fn (string $id) => $this->findPerson($user, $id, 'participantIds'));
    }
}
