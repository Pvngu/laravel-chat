<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\ChatManager;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Models\Conversation;

abstract class Controller
{
    public function __construct(
        protected readonly ChatManager $chat,
        protected readonly ChatUserProvider $users,
    ) {}

    /** A conversation as $user sees it, ready for ConversationResource / ChannelResource. */
    protected function forViewer(Model $user, int $conversationId): Conversation
    {
        return Conversation::query()
            ->withViewerState($user)
            ->with(Conversation::presentationRelations())
            ->findOrFail($conversationId);
    }

    /** The person behind a public id, provided $viewer may see them (same tenant). */
    protected function findPerson(Model $viewer, string $publicId, string $field): Model
    {
        $person = $this->users->find($publicId);

        if (! $person || $this->users->tenant($person) !== $this->users->tenant($viewer)) {
            throw ValidationException::withMessages([$field => "There's nobody with the id $publicId."]);
        }

        return $person;
    }
}
