<?php

namespace Pvngu\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pvngu\Chat\Contracts\ChatPresence;
use Pvngu\Chat\Support\Features;
use Symfony\Component\HttpFoundation\Response;

// Anyone using the chat API is around: it keeps their connection's status (online when it's new)
class RecordPresence
{
    public function __construct(private readonly ChatPresence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Features::enabled('presence') && $user = $request->user()) {
            $this->presence->touch($user, connection: $request->header('X-Socket-ID'));
        }

        return $next($request);
    }
}
