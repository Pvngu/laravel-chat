<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\Http\Requests\LeaveRequest;
use Pvngu\Chat\Http\Requests\MemberRequest;
use Pvngu\Chat\Http\Requests\MemberRoleRequest;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Participant;

// Members of group conversations and channels
class MemberController extends Controller
{
    public function store(MemberRequest $request, Conversation $conversation): Response
    {
        Gate::authorize('addMembers', $conversation);
        $person = $this->findPerson($request->user(), $request->input('userId'), 'userId');
        $this->chat->addMember($conversation, $person, by: $request->user());

        return response()->noContent();
    }

    public function destroy(Request $request, Conversation $conversation, string $userId): Response
    {
        $person = $this->findPerson($request->user(), $userId, 'userId');
        Gate::authorize('removeMember', [$conversation, $person]);
        $this->chat->removeMember($conversation, $person, by: $request->user());

        return response()->noContent();
    }

    // The last admin leaving may choose who takes over (successorId); otherwise it's whoever has been in it longest
    public function leave(LeaveRequest $request, Conversation $conversation): Response
    {
        Gate::authorize('removeMember', [$conversation, $request->user()]);
        $successor = $request->filled('successorId') ? $this->member($request, $conversation, $request->input('successorId'), 'successorId') : null;
        $this->chat->removeMember($conversation, $request->user(), successor: $successor);

        return response()->noContent();
    }

    // Admins make someone an admin, or a member again; a chat keeps at least one admin
    public function update(MemberRoleRequest $request, Conversation $conversation, string $userId): Response
    {
        Gate::authorize('manageRoles', $conversation);
        $person = $this->member($request, $conversation, $userId, 'userId');
        Gate::authorize('manageRoles', [$conversation, $person]); // not the creator
        if ($request->input('role') === Participant::MEMBER && $this->chat->isLastAdmin($conversation, $person)) {
            throw ValidationException::withMessages(['role' => 'Make someone else an admin first.']);
        }
        $this->chat->setRole($conversation, $person, $request->input('role'), by: $request->user());

        return response()->noContent();
    }

    // Someone in this conversation, by public id
    private function member(Request $request, Conversation $conversation, string $publicId, string $field): Model
    {
        $person = $this->findPerson($request->user(), $publicId, $field);
        if (! $conversation->participants()->where('user_id', $person->getKey())->exists()) {
            throw ValidationException::withMessages([$field => 'They aren\'t in this conversation.']);
        }

        return $person;
    }
}
