<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Pvngu\Chat\Support\Features;

class FeatureController extends Controller
{
    // What the chat can do here, for the frontend to leave out what's off
    public function index(): JsonResponse
    {
        return response()->json(['data' => Features::all()]);
    }
}
