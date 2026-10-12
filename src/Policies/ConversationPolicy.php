<?php

namespace Pvngu\Chat\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Models\Conversation;

/**
 * Members see and use their conversations; anyone in the same tenant may read and join public channels.
 * Everything else answers 404, so ids of other people's conversations can't be probed.
 * Register your own policy for Conversation::class to change these rules.
 */
class ConversationPolicy
{
    public function __construct(private readonly ChatUserProvider $users) {}

    public function view(Model $user, Conversation $conversation): Response
    {
        if (! $this->sameTenant($user, $conversation)) {
            return Response::denyAsNotFound();
        }

        return $conversation->participantFor($user) || $conversation->isPublicChannel()
            ? Response::allow()
            : Response::denyAsNotFound();
    }

    // Posting in a public channel joins it
    public function send(Model $user, Conversation $conversation): Response
    {
        return $this->view($user, $conversation);
    }

    // Read state, pins, favorites, archive: your own view of a conversation you're in
    public function participate(Model $user, Conversation $conversation): Response
    {
        return $this->member($user, $conversation) ? Response::allow() : Response::denyAsNotFound();
    }

    public function addMembers(Model $user, Conversation $conversation): Response
    {
        if (! $this->member($user, $conversation)) {
            return Response::denyAsNotFound();
        }

        return $conversation->isDirect()
            ? Response::deny('People can\'t be added to a direct message; start a group instead.')
            : Response::allow();
    }

    // Leaving is always allowed; removing someone else takes an admin
    public function removeMember(Model $user, Conversation $conversation, Model $member): Response
    {
        $participant = $this->member($user, $conversation);
        if (! $participant) {
            return Response::denyAsNotFound();
        }
        if ($conversation->isDirect()) {
            return Response::deny('Nobody can leave or be removed from a direct message.');
        }
        if ((string) $member->getKey() === (string) $user->getKey() || $participant->isAdmin()) {
            return Response::allow();
        }

        return Response::deny('Only admins can remove other people.');
    }

    private function member(Model $user, Conversation $conversation)
    {
        return $this->sameTenant($user, $conversation) ? $conversation->participantFor($user) : null;
    }

    private function sameTenant(Model $user, Conversation $conversation): bool
    {
        return $conversation->tenant === $this->users->tenant($user);
    }
}
