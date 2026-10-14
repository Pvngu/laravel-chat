<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Support\Formatting;
use Pvngu\Chat\Tests\TestCase;

class FormattingTest extends TestCase
{
    public function test_formatting_markers_are_dropped_for_reading(): void
    {
        $this->assertSame('Plan for Friday, really not', Formatting::plain('**Plan** for _Friday_, __really__ ~~not~~'));
        $this->assertSame('see the docs and npm run **dev**', Formatting::plain('see [the docs](https://example.com) and `npm run **dev**`'));
        $this->assertSame("quoted\n- one\n1. two\nnpm ci", Formatting::plain("> quoted\n- one\n1. two\n```\nnpm ci\n```"));
        $this->assertSame('code', Formatting::plain('```code```'));
        $this->assertSame('unbelievable', Formatting::plain('un**believ**able'));
        // Everyday text stays as it is
        foreach (['snake_case_name', '2*3*4', 'a ** b', 'https://example.com/_path_', 'plain'] as $plain) {
            $this->assertSame($plain, Formatting::plain($plain));
        }
    }

    public function test_previews_and_reply_quotes_read_without_markers(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $conversation = $this->directBetween($ann, $john);
        $message = Chat::send($conversation, $ann, "**Heads up**, <@{$john->id}>: _launch_ moves");

        $this->actingAs($john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.lastMessage.text', 'Heads up, @John Doe: launch moves');

        // The message itself keeps them, to be shown formatted
        $this->actingAs($john)
            ->postJson("/api/chat/conversations/{$conversation->id}/messages", ['body' => 'Got it', 'replyToId' => (string) $message->id])
            ->assertCreated()
            ->assertJsonPath('data.replyTo.text', 'Heads up, @John Doe: launch moves');
        $this->actingAs($john)->getJson("/api/chat/conversations/{$conversation->id}/messages")
            ->assertJsonPath('data.0.text', "**Heads up**, <@{$john->id}>: _launch_ moves");
    }
}
