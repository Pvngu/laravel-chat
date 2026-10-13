<?php

namespace Pvngu\Chat\Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Orchestra\Testbench\TestCase as Orchestra;
use Pvngu\Chat\ChatServiceProvider;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Tests\Fixtures\User;

abstract class TestCase extends Orchestra
{
    use RefreshDatabase;

    protected function getPackageProviders($app): array
    {
        return [ChatServiceProvider::class];
    }

    protected function getPackageAliases($app): array
    {
        return ['Chat' => Chat::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('laravel-chat.routes.middleware', ['api', 'auth']);
        $app['config']->set('laravel-chat.user_attributes.tenant', 'team');
        $app['config']->set('laravel-chat.user_attributes.avatar', 'avatar_url');
        $app['config']->set('broadcasting.default', 'null');
        $app['config']->set('cache.default', 'array'); // presence
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/migrations');
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function person(string $name, string $team = 'acme'): User
    {
        return User::query()->create([
            'name' => $name,
            'email' => strtolower(str_replace(' ', '.', $name)).'@example.com',
            'team' => $team,
        ]);
    }

    protected function directBetween(User $a, User $b): Conversation
    {
        return Chat::direct($a, $b);
    }
}
