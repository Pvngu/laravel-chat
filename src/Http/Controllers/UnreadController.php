<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UnreadController extends Controller
{
    // How many chats have a badge in the viewer's sidebar, and how many unread mentions: for a badge outside the chat
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->chat->unread($request->user())]);
    }
}
