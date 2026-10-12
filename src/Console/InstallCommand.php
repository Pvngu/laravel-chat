<?php

namespace Pvngu\Chat\Console;

use Illuminate\Console\Command;

class InstallCommand extends Command
{
    protected $signature = 'chat:install {--force : Overwrite the published config}';

    protected $description = 'Publish the chat config and migrations';

    public function handle(): int
    {
        $this->call('vendor:publish', ['--tag' => 'laravel-chat-config', '--force' => $this->option('force')]);
        $this->call('vendor:publish', ['--tag' => 'laravel-chat-migrations']);

        $this->newLine();
        $this->components->info('Next steps');
        $this->components->bulletList([
            'Check config/laravel-chat.php: your user model, route middleware (auth guard) and, for multi-tenant apps, user_attributes.tenant.',
            'If your users table key isn\'t a big integer, set user_key_type before migrating.',
            'Run php artisan migrate.',
            'For real-time updates, set up broadcasting (php artisan install:broadcasting) and make sure channel authorization routes are registered.',
            'Frontend: npm install @pvngu/vue-chat, then useChatStore({ adapter: createLaravelAdapter(), currentUser }).',
        ]);

        return self::SUCCESS;
    }
}
