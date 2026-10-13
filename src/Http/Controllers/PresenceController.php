<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Response;

class PresenceController extends Controller
{
    // A heartbeat from an open chat page; RecordPresence has already noted it
    public function store(): Response
    {
        return response()->noContent();
    }
}
