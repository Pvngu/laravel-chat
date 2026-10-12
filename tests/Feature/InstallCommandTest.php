<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\File;
use Pvngu\Chat\Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_it_publishes_the_config_and_migrations(): void
    {
        $config = config_path('laravel-chat.php');
        $before = File::glob(database_path('migrations/*_create_chat_tables.php'));

        try {
            $this->artisan('chat:install')->assertSuccessful();

            $this->assertFileExists($config);
            $this->assertCount(count($before) + 1, File::glob(database_path('migrations/*_create_chat_tables.php')));
        } finally {
            File::delete($config);
            File::delete(array_diff(File::glob(database_path('migrations/*_create_chat_tables.php')), $before));
        }
    }
}
