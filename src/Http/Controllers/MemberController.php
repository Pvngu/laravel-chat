<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Pvngu\Chat\Http\Requests\MemberRequest;
use Pvngu\Chat\Models\Conversation;

// Members of group conversations and channels
class MemberController extends Controller
{
    public function store(MemberRequest $request, Conversation $conversation): Response
    {
        Gate::authorize('addMembers', $conversation);
        $person = $this->findPerson($request->user(), $request->input('userId'), 'userId');
        $this->chat->addParticipant($conversation, $person);

        return response()->noContent();
    }

    public function destroy(Request $request, Conversation $conversation, string $userId): Response
    {
        $person = $this->findPerson($request->user(), $userId, 'userId');
        Gate::authorize('removeMember', [$conversation, $person]);
        $this->chat->removeParticipant($conversation, $person);

        return response()->noContent();
    }

    public function leave(Request $request, Conversation $conversation): Response
    {
        Gate::authorize('removeMember', [$conversation, $request->user()]);
        $this->chat->removeParticipant($conversation, $request->user());

        return response()->noContent();
    }
}
