<?php

namespace Pvngu\Chat\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Pvngu\Chat\Http\Requests\ReminderRequest;
use Pvngu\Chat\Http\Resources\ReminderResource;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Models\Reminder;

// Your reminders about messages: one per message, sent once at its time (ReminderDue)
class ReminderController extends Controller
{
    // The ones still to come
    public function index(Request $request): AnonymousResourceCollection
    {
        $reminders = Reminder::query()
            ->where('user_id', $request->user()->getKey())
            ->whereNull('sent_at')
            ->where('remind_at', '>', now())
            ->whereHas('message', fn ($query) => $query->withoutTrashed())
            ->with('message.conversation')
            ->orderBy('remind_at')
            ->get();

        return ReminderResource::collection($reminders);
    }

    // Set, or move, the reminder for a message: { remindAt }
    public function update(ReminderRequest $request, Message $message): ReminderResource
    {
        Gate::authorize('view', $message->conversation);
        if ($message->isEvent()) {
            throw ValidationException::withMessages(['remindAt' => 'That message can’t have a reminder.']);
        }

        $reminder = $this->chat->remind($request->user(), $message, Carbon::parse($request->validated('remindAt')));

        return new ReminderResource($reminder->load('message.conversation'));
    }

    public function destroy(Request $request, Message $message): Response
    {
        Gate::authorize('view', $message->conversation);
        $this->chat->forgetReminder($request->user(), $message);

        return response()->noContent();
    }
}
