<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\File;
use Pvngu\Chat\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_it_publishes_the_config_and_migrations(): void
    {
        $config = config_path('laravel-chat.php');
        $migrations = fn () => File::glob(database_path('migrations/*_create_chat_{tables,reminders_table}.php'), GLOB_BRACE);
        $before = $migrations();

        try {
            $this->artisan('chat:install')->assertSuccessful();

            $this->assertFileExists($config);
            $published = array_values(array_diff($migrations(), $before));
            $this->assertCount(2, $published);
            // The reminders table refers to the messages table, so it runs after it
            sort($published);
            $this->assertStringEndsWith('_create_chat_tables.php', $published[0]);
            $this->assertStringEndsWith('_create_chat_reminders_table.php', $published[1]);
        } finally {
            File::delete($config);
            File::delete(array_diff($migrations(), $before));
        }
    }
}
