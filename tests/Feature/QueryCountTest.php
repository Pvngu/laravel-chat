<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\DB;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

// The lists load everything they show in a fixed number of queries, however long they are
class QueryCountTest extends TestCase
{
    public function test_listing_conversations_and_messages_does_not_query_per_row(): void
    {
        $ann = $this->person('Ann Smith');
        $countFor = function (string $url) use ($ann) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($ann)->getJson($url)->assertOk();

            return count(DB::getQueryLog());
        };

        $grow = function (int $n) use ($ann) {
            foreach (range(1, $n) as $i) {
                $other = $this->person("Person $i ".uniqid());
                $conversation = Chat::direct($ann, $other);
                $message = Chat::send($conversation, $other, "Hello $i");
                Chat::react($message, $ann, '👍');
            }

            return $conversation;
        };

        $last = $grow(2);
        $conversations = $countFor('/api/chat/conversations');
        $messages = $countFor("/api/chat/conversations/{$last->id}/messages");

        $last = $grow(6);
        foreach (range(1, 5) as $i) {
            Chat::send($last, $ann, "More $i");
        }

        $this->assertSame($conversations, $countFor('/api/chat/conversations'));
        $this->assertSame($messages, $countFor("/api/chat/conversations/{$last->id}/messages"));
    }

    public function test_the_unread_totals_are_one_query_however_many_chats(): void
    {
        $ann = $this->person('Ann Smith');
        $grow = function (int $n) use ($ann) {
            foreach (range(1, $n) as $i) {
                $other = $this->person("Person $i ".uniqid());
                Chat::send(Chat::direct($ann, $other), $other, "Hello <@{$ann->id}>");
                $channel = Chat::channel($other, "Channel $i");
                Chat::addParticipant($channel, $ann);
                Chat::send($channel, $other, 'News');
                Chat::send(Chat::channel($other, "Public $i"), $other, 'Anyone?'); // one Ann could join
            }
        };
        $countFor = function () use ($ann) {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($ann)->getJson('/api/chat/unread')->assertOk();

            return count(DB::getQueryLog());
        };

        $grow(2);
        $this->assertSame(1, $countFor());
        $grow(6);
        $this->assertSame(1, $countFor());
        $this->actingAs($ann)->getJson('/api/chat/unread')->assertJsonPath('data', ['chats' => 16, 'mentions' => 8]);
    }
}
