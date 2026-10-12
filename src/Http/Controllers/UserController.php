<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class UserController extends Controller
{
    // People the viewer can start a conversation with or add to one
    public function index(Request $request): JsonResponse
    {
        $people = $this->users->available($request->user())
            ->map(fn (Model $person) => $this->users->present($person))
            ->values();

        return response()->json(['data' => $people]);
    }
}
