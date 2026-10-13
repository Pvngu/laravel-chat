<?php

use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Pvngu\Chat\Http\Controllers\AttachmentController;
use Pvngu\Chat\Http\Controllers\ChannelController;
use Pvngu\Chat\Http\Controllers\ConversationController;
use Pvngu\Chat\Http\Controllers\MemberController;
use Pvngu\Chat\Http\Controllers\MessageController;
use Pvngu\Chat\Http\Controllers\PresenceController;
use Pvngu\Chat\Http\Controllers\ReactionController;
use Pvngu\Chat\Http\Controllers\UserController;
use Pvngu\Chat\Http\Middleware\RecordPresence;

$prefix = config('laravel-chat.routes.prefix', 'api/chat');
$name = config('laravel-chat.routes.name', 'chat.');

// Chat ids are numeric; anything else is a 404 (and never reaches PostgreSQL as a bigint comparison)
$ids = ['conversation' => '[0-9]+', 'message' => '[0-9]+', 'attachment' => '[0-9]+'];

Route::prefix($prefix)->name($name)->where($ids)->group(function () {
    // Signed, expiring links instead of auth, so attachments load in <img> tags
    Route::get('attachments/{attachment}', [AttachmentController::class, 'show'])
        ->middleware(['signed', SubstituteBindings::class])
        ->name('attachments.show');

    // Every request counts as being online, for `@here`
    $middleware = [...config('laravel-chat.routes.middleware', ['api', 'auth:sanctum']), SubstituteBindings::class, RecordPresence::class];
    Route::middleware($middleware)->group(function () {
        Route::get('conversations', [ConversationController::class, 'index'])->name('conversations.index');
        Route::post('conversations', [ConversationController::class, 'store'])->name('conversations.store');
        Route::post('conversations/{conversation}/pin', [ConversationController::class, 'pin'])->name('conversations.pin');
        Route::post('conversations/{conversation}/archive', [ConversationController::class, 'archive'])->name('conversations.archive');

        Route::get('channels', [ChannelController::class, 'index'])->name('channels.index');
        Route::post('channels', [ChannelController::class, 'store'])->name('channels.store');

        // Conversations and channels share these: the frontend addresses a chat as {kind}s/{id}
        foreach (['conversations', 'channels'] as $kind) {
            Route::get("$kind/{conversation}", [ConversationController::class, 'show'])->name("$kind.show");
            Route::patch("$kind/{conversation}", [ConversationController::class, 'update'])->name("$kind.update");
            Route::post("$kind/{conversation}/notifications", [ConversationController::class, 'notifications'])->name("$kind.notifications");
            Route::get("$kind/{conversation}/messages", [MessageController::class, 'index'])->name("$kind.messages.index");
            Route::post("$kind/{conversation}/messages", [MessageController::class, 'store'])->name("$kind.messages.store");
            Route::get("$kind/{conversation}/files", [AttachmentController::class, 'index'])->name("$kind.files.index");
            Route::post("$kind/{conversation}/read", [ConversationController::class, 'read'])->name("$kind.read");
            Route::post("$kind/{conversation}/favorite", [ConversationController::class, 'favorite'])->name("$kind.favorite");
            Route::post("$kind/{conversation}/members", [MemberController::class, 'store'])->name("$kind.members.store");
            Route::delete("$kind/{conversation}/members/me", [MemberController::class, 'leave'])->name("$kind.members.leave");
            Route::patch("$kind/{conversation}/members/{userId}", [MemberController::class, 'update'])->name("$kind.members.update");
            Route::delete("$kind/{conversation}/members/{userId}", [MemberController::class, 'destroy'])->name("$kind.members.destroy");
        }

        Route::patch('messages/{message}', [MessageController::class, 'update'])->name('messages.update');
        Route::delete('messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
        Route::post('messages/{message}/pin', [MessageController::class, 'pin'])->name('messages.pin');
        Route::post('messages/{message}/reactions', [ReactionController::class, 'store'])->name('messages.reactions.store');
        Route::delete('messages/{message}/reactions/{emoji}', [ReactionController::class, 'destroy'])->name('messages.reactions.destroy');

        Route::get('users', [UserController::class, 'index'])->name('users.index');
        Route::get('presence', [PresenceController::class, 'show'])->name('presence.show');
        Route::post('presence', [PresenceController::class, 'store'])->name('presence');
    });
});
