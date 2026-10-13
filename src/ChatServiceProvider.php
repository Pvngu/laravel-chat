<?php

namespace Pvngu\Chat;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Pvngu\Chat\Console\InstallCommand;
use Pvngu\Chat\Contracts\ChatPresence;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Models\Message;
use Pvngu\Chat\Policies\ConversationPolicy;
use Pvngu\Chat\Policies\MessagePolicy;
use Pvngu\Chat\Support\ChatConfig;

class ChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/laravel-chat.php', 'laravel-chat');

        $this->app->singleton(ChatUserProvider::class, fn ($app) => $app->make(config('laravel-chat.user_provider')));
        $this->app->singleton(ChatPresence::class, fn ($app) => $app->make(config('laravel-chat.presence.driver')));
        $this->app->singleton(ChatManager::class);
    }

    public function boot(): void
    {
        // App service providers boot after packages, so a Gate::policy() of your own replaces these
        Gate::policy(Conversation::class, ConversationPolicy::class);
        Gate::policy(Message::class, MessagePolicy::class);

        if (config('laravel-chat.routes.enabled', true)) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }

        if (ChatConfig::broadcasts()) {
            $this->registerBroadcastChannels();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/laravel-chat.php' => config_path('laravel-chat.php')], 'laravel-chat-config');
            // Timestamped like the app's own migrations, so it runs after the users table exists
            $this->publishesMigrations([
                __DIR__.'/../database/migrations/create_chat_tables.php' => database_path('migrations/'.date('Y_m_d_His').'_create_chat_tables.php'),
            ], 'laravel-chat-migrations');
            $this->commands([InstallCommand::class]);
        }
    }

    // Who may listen: members of a conversation (anyone in the tenant for a public channel), and each person to
    // their own user channel. Registered once the app uses broadcasting, so no driver is built before then.
    private function registerBroadcastChannels(): void
    {
        $this->callAfterResolving(BroadcastManager::class, function (BroadcastManager $broadcast) {
            $broadcast->channel(ChatConfig::channel('conversation.{conversationId}'), function (Model $user, string $conversationId) {
                $conversation = ctype_digit($conversationId) ? Conversation::query()->find($conversationId) : null;

                return $conversation !== null && Gate::forUser($user)->allows('view', $conversation);
            });

            $broadcast->channel(ChatConfig::channel('user.{publicId}'), function (Model $user, string $publicId) {
                return app(ChatUserProvider::class)->publicId($user) === $publicId;
            });

            // Presence: everyone in a tenant joins its channel while the chat is open (online), and is told who else is
            $broadcast->channel(ChatConfig::channel('presence.{key}'), function (Model $user, string $key) {
                if (app(ChatManager::class)->presenceChannel($user) !== ChatConfig::channel("presence.$key")) {
                    return false;
                }
                $person = app(ChatUserProvider::class)->present($user);

                return ['id' => $person['id'], 'name' => $person['name']];
            });
        });
    }
}
