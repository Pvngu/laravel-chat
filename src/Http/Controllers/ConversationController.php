<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Pvngu\Chat\Http\Requests\NotificationsRequest;
use Pvngu\Chat\Http\Requests\StoreConversationRequest;
use Pvngu\Chat\Http\Requests\UpdateConversationRequest;
use Pvngu\Chat\Http\Resources\ChannelResource;
use Pvngu\Chat\Http\Resources\ConversationResource;
use Pvngu\Chat\Models\Conversation;

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

    // A direct message (the existing one when there is one) or a group conversation
    public function store(StoreConversationRequest $request)
    {
        $user = $request->user();
        $people = collect($request->input('participantIds'))
            ->unique()
            ->map(fn (string $id) => $this->findPerson($user, $id, 'participantIds'));

        $conversation = $request->boolean('isDirect') && $people->count() === 1
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
}
