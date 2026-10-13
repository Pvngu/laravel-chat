<?php

namespace Pvngu\Chat\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Pvngu\Chat\Models\Message;

/**
 * Senders edit and delete their own messages (conversation admins may delete any); everyone who can see a
 * conversation can react; members can pin. Membership lines ("joined", "left"…) are left as they are. Register your
 * own policy for Message::class to change these rules.
 */
class MessagePolicy
{
    public function update(Model $user, Message $message): Response
    {
        if (! $this->canSee($user, $message)) {
            return Response::denyAsNotFound();
        }
        if ($message->isEvent()) {
            return Response::deny('Membership changes can\'t be changed.');
        }

        return $this->isSender($user, $message) ? Response::allow() : Response::deny('Only the sender can edit a message.');
    }

    public function delete(Model $user, Message $message): Response
    {
        if (! $this->canSee($user, $message)) {
            return Response::denyAsNotFound();
        }
        if ($message->isEvent()) {
            return Response::deny('Membership changes can\'t be changed.');
        }

        $isAdmin = (bool) $message->conversation->participantFor($user)?->isAdmin();

        return $this->isSender($user, $message) || $isAdmin
            ? Response::allow()
            : Response::deny('Only the sender or an admin can delete a message.');
    }

    public function react(Model $user, Message $message): Response
    {
        if (! $this->canSee($user, $message)) {
            return Response::denyAsNotFound();
        }

        return $message->isEvent() ? Response::deny('Membership changes can\'t be reacted to.') : Response::allow();
    }

    public function pin(Model $user, Message $message): Response
    {
        if (! $this->canSee($user, $message)) {
            return Response::denyAsNotFound();
        }
        if ($message->isEvent()) {
            return Response::deny('Membership changes can\'t be changed.');
        }

        return $message->conversation->participantFor($user) ? Response::allow() : Response::deny('Join to pin messages.');
    }

    private function canSee(Model $user, Message $message): bool
    {
        return Gate::forUser($user)->allows('view', $message->conversation);
    }

    private function isSender(Model $user, Message $message): bool
    {
        return (string) $message->sender_id === (string) $user->getKey();
    }
}
