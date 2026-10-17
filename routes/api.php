<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Pvngu\Chat\Http\Controllers\AttachmentController;
use Pvngu\Chat\Http\Controllers\ChannelController;
use Pvngu\Chat\Http\Controllers\ConversationController;
use Pvngu\Chat\Http\Controllers\FeatureController;
use Pvngu\Chat\Http\Controllers\MemberController;
use Pvngu\Chat\Http\Controllers\MessageController;
use Pvngu\Chat\Http\Controllers\PresenceController;
use Pvngu\Chat\Http\Controllers\ReactionController;
use Pvngu\Chat\Http\Controllers\ReminderController;
use Pvngu\Chat\Http\Controllers\SavedMessageController;
use Pvngu\Chat\Http\Controllers\ThreadController;
use Pvngu\Chat\Http\Controllers\UnreadController;
use Pvngu\Chat\Http\Controllers\UserController;
use Pvngu\Chat\Http\Middleware\EnsureFeatureEnabled;
use Pvngu\Chat\Http\Middleware\RecordPresence;

$prefix = config('laravel-chat.routes.prefix', 'api/chat');
$name = config('laravel-chat.routes.name', 'chat.');

// Chat ids are numeric; anything else is a 404 (and never reaches PostgreSQL as a bigint comparison)
$ids = ['conversation' => '[0-9]+', 'message' => '[0-9]+', 'attachment' => '[0-9]+'];

Route::prefix($prefix)->name($name)->where($ids)->group(function () {
    // A feature's routes are a 404 when laravel-chat.features turns it off
    $feature = fn (string $name) => EnsureFeatureEnabled::class.":$name";

    // Signed, expiring links instead of auth, so attachments load in <img> tags
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
        ->middleware(['signed', SubstituteBindings::class])
        ->name('attachments.show');

    // For a badge anywhere in the app (outside the chat too), so asking isn't being around in the chat
    $auth = config('laravel-chat.routes.middleware', ['api', 'auth:sanctum']);
    Route::get('unread', [UnreadController::class, 'show'])->middleware($auth)->name('unread');

    // Every request counts as being online, for `@here`
    $middleware = [...$auth, SubstituteBindings::class, RecordPresence::class];
    Route::middleware($middleware)->group(function () use ($feature) {
        Route::get('features', [FeatureController::class, 'index'])->name('features.index');

        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
        Route::post('conversations/{conversation}/pin', [ConversationController::class, 'pin'])->name('conversations.pin');
        Route::post('conversations/{conversation}/archive', [ConversationController::class, 'archive'])->name('conversations.archive');

        Route::middleware($feature('channels'))->group(function () {
            Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
            Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');
        });

        // Conversations and channels share these: the frontend addresses a chat as {kind}s/{id}
        foreach (['conversations', 'channels'] as $kind) {
            Route::middleware($kind === 'channels' ? [$feature('channels')] : [])->group(function () use ($kind, $feature) {
                Route::get("$kind/{conversation}", [ConversationController::class, 'show'])->name("$kind.show");
                Route::patch("$kind/{conversation}", [ConversationController::class, 'update'])->name("$kind.update");
                Route::post("$kind/{conversation}/notifications", [ConversationController::class, 'notifications'])
                    ->middleware($feature('notifications'))->name("$kind.notifications");
                Route::get("$kind/{conversation}/messages", [MessageController::class, 'index'])->name("$kind.messages.index");
                Route::post("$kind/{conversation}/messages", [MessageController::class, 'store'])->name("$kind.messages.store");
                Route::get("$kind/{conversation}/files", [AttachmentController::class, 'index'])
                    ->middleware($feature('files'))->name("$kind.files.index");
                Route::post("$kind/{conversation}/read", [ConversationController::class, 'read'])->name("$kind.read");
                Route::post("$kind/{conversation}/favorite", [ConversationController::class, 'favorite'])->name("$kind.favorite");
                Route::post("$kind/{conversation}/members", [MemberController::class, 'store'])->name("$kind.members.store");
                Route::delete("$kind/{conversation}/members/me", [MemberController::class, 'leave'])->name("$kind.members.leave");
                Route::patch("$kind/{conversation}/members/{userId}", [MemberController::class, 'update'])->name("$kind.members.update");
                Route::delete("$kind/{conversation}/members/{userId}", [MemberController::class, 'destroy'])->name("$kind.members.destroy");
            });
        }

        Route::patch('messages/{message}', [MessageController::class, 'update'])->middleware($feature('editing'))->name('messages.update');
        Route::delete('messages/{message}', [MessageController::class, 'destroy'])->middleware($feature('deleting'))->name('messages.destroy');
        Route::post('messages/{message}/pin', [MessageController::class, 'pin'])->middleware($feature('pinning'))->name('messages.pin');

        Route::middleware($feature('reactions'))->group(function () {
            Route::post('messages/{message}/reactions', [ReactionController::class, 'store'])->name('messages.reactions.store');
            Route::delete('messages/{message}/reactions/{emoji}', [ReactionController::class, 'destroy'])->name('messages.reactions.destroy');
        });

        Route::middleware($feature('reminders'))->group(function () {
            Route::put('messages/{message}/reminder', [ReminderController::class, 'update'])->name('messages.reminder.update');
            Route::delete('messages/{message}/reminder', [ReminderController::class, 'destroy'])->name('messages.reminder.destroy');
            Route::get('reminders', [ReminderController::class, 'index'])->name('reminders.index');
        });

        Route::middleware($feature('saved'))->group(function () {
            Route::put('messages/{message}/saved', [SavedMessageController::class, 'update'])->name('messages.saved.update');
            Route::delete('messages/{message}/saved', [SavedMessageController::class, 'destroy'])->name('messages.saved.destroy');
            Route::get('saved', [SavedMessageController::class, 'index'])->name('saved.index');
        });

        Route::middleware($feature('threads'))->group(function () {
            Route::get('messages/{message}/replies', [ThreadController::class, 'replies'])->name('messages.replies.index');
            Route::post('messages/{message}/replies', [ThreadController::class, 'reply'])->name('messages.replies.store');
            Route::post('messages/{message}/thread/read', [ThreadController::class, 'read'])->name('messages.thread.read');
            Route::get('threads', [ThreadController::class, 'index'])->name('threads.index');
        });

        Route::get('users', [UserController::class, 'index'])->name('users.index');

        Route::middleware($feature('presence'))->group(function () {
            Route::get('presence', [PresenceController::class, 'show'])->name('presence.show');
            Route::post('presence', [PresenceController::class, 'store'])->name('presence');
        });
    });
});
