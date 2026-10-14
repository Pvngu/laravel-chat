<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// A reply in a thread that was also sent to its chat ("Also send to #channel"): it shows in both. Its own migration,
// so apps that already have the threads columns only need to publish and run this one.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(config('laravel-chat.table_prefix', 'chat_').'messages', function (Blueprint $table) {
            $table->boolean('also_in_chat')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table(config('laravel-chat.table_prefix', 'chat_').'messages', function (Blueprint $table) {
            $table->dropColumn('also_in_chat');
        });
    }
};
