<?php

namespace Pvngu\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pvngu\Chat\Contracts\ChatPresence;
use Symfony\Component\HttpFoundation\Response;

// Anyone using the chat API is online (for `@here`)
class RecordPresence
{
    public function __construct(private readonly ChatPresence $presence) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            $this->presence->touch($user);
        }

        return $next($request);
    }
}
