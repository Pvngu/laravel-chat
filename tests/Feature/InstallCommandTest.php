<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\File;
use Pvngu\Chat\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_it_publishes_the_config_and_migrations(): void
    {
        $config = config_path('laravel-chat.php');
        $migrations = fn () => File::glob(database_path('migrations/*_{create_chat_tables,create_chat_reminders_table,create_chat_saved_messages_table,update_chat_messages_for_threads}.php'), GLOB_BRACE);
        $before = $migrations();

        try {
            $this->artisan('chat:install')->assertSuccessful();

            $this->assertFileExists($config);
            $published = array_values(array_diff($migrations(), $before));
            $this->assertCount(4, $published);
            // The reminders, saved messages and threads migrations build on the messages table, so they run after it
            usort($published, fn (string $a, string $b) => strcmp(basename($a), basename($b)));
            $this->assertStringEndsWith('_create_chat_tables.php', $published[0]);
            $this->assertStringEndsWith('_create_chat_reminders_table.php', $published[1]);
            $this->assertStringEndsWith('_create_chat_saved_messages_table.php', $published[2]);
            $this->assertStringEndsWith('_update_chat_messages_for_threads.php', $published[3]);
        } finally {
            File::delete($config);
            File::delete(array_diff($migrations(), $before));
        }
    }
}
