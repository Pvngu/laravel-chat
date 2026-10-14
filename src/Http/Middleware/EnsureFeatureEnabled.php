<?php

namespace Pvngu\Chat\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Pvngu\Chat\Support\Features;
use Symfony\Component\HttpFoundation\Response;

// The routes of a feature the app turned off (laravel-chat.features) don't exist
class EnsureFeatureEnabled
{
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        abort_unless(Features::enabled($feature), 404);

        return $next($request);
    }
}
