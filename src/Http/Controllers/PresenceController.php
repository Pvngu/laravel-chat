<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Pvngu\Chat\Contracts\ChatPresence;
use Pvngu\Chat\Events\PresenceChanged;
use Pvngu\Chat\Http\Requests\PresenceRequest;

class PresenceController extends Controller
{
    /**
     * The presence channel to join (joining and leaving it is going online and offline), and, for `?ids=a,b,c`, who of
     * them is online or away right now.
     */
    public function show(Request $request, ChatPresence $presence): JsonResponse
    {
        $user = $request->user();
        $ids = array_slice(array_values(array_filter(explode(',', (string) $request->query('ids', '')))), 0, 500);

        return response()->json(['data' => [
            'channel' => $this->chat->presenceChannel($user),
            'statuses' => (object) $presence->statuses($user, $ids),
        ]]);
    }

    // A heartbeat from an open chat page, with its status; everyone else hears about a change
    public function store(PresenceRequest $request, ChatPresence $presence): Response
    {
        $user = $request->user();
        $changed = $presence->touch($user, $request->validated('status', ChatPresence::ONLINE), $request->header('X-Socket-ID'));

        if ($changed) {
            $id = $this->users->publicId($user);
            $status = $presence->statuses($user, [$id])[$id] ?? ChatPresence::ONLINE;
            PresenceChanged::dispatch($this->chat->presenceChannel($user), $id, $status);
        }

        return response()->noContent();
    }
}
