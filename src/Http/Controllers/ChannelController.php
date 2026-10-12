<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Pvngu\Chat\Http\Requests\StoreChannelRequest;
use Pvngu\Chat\Http\Resources\ChannelResource;
use Pvngu\Chat\Models\Conversation;

class ChannelController extends Controller
{
    // Channels the viewer is in, plus the public ones they can join
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $table = (new Conversation)->getTable();

        $channels = Conversation::query()
            ->channelsVisibleTo($user, $this->users->tenant($user))
            ->with('participants.user')
            ->orderBy("$table.name")
            ->get();

        return ChannelResource::collection($channels);
    }

    public function store(StoreChannelRequest $request)
    {
        $user = $request->user();
        $channel = $this->chat->channel(
            $user,
            $request->input('name'),
            $request->boolean('isPrivate'),
            $request->input('description'),
        );

        return (new ChannelResource($this->forViewer($user, $channel->id)))->response()->setStatusCode(201);
    }
}
