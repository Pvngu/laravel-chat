<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class MessagesTest extends TestCase
{
    private User $ann;

    private User $john;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ann = $this->person('Ann Smith');
        $this->john = $this->person('John Doe');
        $this->conversation = $this->directBetween($this->ann, $this->john);
    }

    private function messagesUrl(?Conversation $conversation = null): string
    {
        return '/api/chat/conversations/'.($conversation ?? $this->conversation)->id.'/messages';
    }

    public function test_it_pages_back_from_the_newest_messages_in_vue_chats_shape(): void
    {
        config(['laravel-chat.pagination.messages' => 2]);
        Carbon::setTestNow('2026-03-10 09:00:00');
        $first = Chat::send($this->conversation, $this->john, 'one');
        Chat::send($this->conversation, $this->ann, 'two');
        Chat::send($this->conversation, $this->john, 'three');

        $page1 = $this->actingAs($this->ann)->getJson($this->messagesUrl())->assertOk();
        // The latest messages, in the order they were sent (all in the same second here)
        $this->assertSame(['two', 'three'], array_column($page1->json('data'), 'text'));
        $this->assertNotNull($page1->json('links.next'));

        $page2 = $this->actingAs($this->ann)->getJson($this->messagesUrl().'?page=2')->assertOk();
        $page2->assertJsonPath('data.0', [
            'id' => (string) $first->id,
            'senderId' => (string) $this->john->id,
            'text' => 'one',
            'sentAt' => '2026-03-10T09:00:00Z',
            'isEdited' => false,
            'isForwarded' => false,
            'isPinned' => false,
            'reactions' => [],
            'attachments' => [],
            'gif' => null,
            'event' => null,
            'mentions' => [],
            'replyTo' => null,
        ]);
        $this->assertNull($page2->json('links.next'));
    }

    public function test_sending_updates_the_conversation_for_everyone(): void
    {
        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['body' => 'Hello'])
            ->assertCreated()
            ->assertJsonPath('data.text', 'Hello')
            ->assertJsonPath('data.senderId', (string) $this->ann->id);

        $this->actingAs($this->john)->getJson('/api/chat/conversations')
            ->assertJsonPath('data.0.lastMessage.text', 'Hello')
            ->assertJsonPath('data.0.unreadCount', 1);
        $this->actingAs($this->ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.unreadCount', 0);
    }

    public function test_attachments_are_stored_and_served_through_signed_links(): void
    {
        // A real local disk without temporary URLs, so links go through the package's signed route
        $root = sys_get_temp_dir().'/laravel-chat-tests-'.uniqid();
        config([
            'filesystems.disks.chat' => ['driver' => 'local', 'root' => $root, 'serve' => false],
            'laravel-chat.attachments.disk' => 'chat',
        ]);

        try {
            $response = $this->actingAs($this->ann)
                ->post($this->messagesUrl(), [
                    'attachments' => [
                        UploadedFile::fake()->image('photo.png', 40, 40),
                        UploadedFile::fake()->create('report.pdf', 120, 'application/pdf'),
                    ],
                ], ['Accept' => 'application/json'])
                ->assertCreated()
                ->assertJsonPath('data.text', '')
                ->assertJsonPath('data.attachments.0.name', 'photo.png')
                ->assertJsonPath('data.attachments.0.isImage', true)
                ->assertJsonPath('data.attachments.1.type', 'application/pdf')
                ->assertJsonPath('data.attachments.1.size', 120 * 1024);

            // The sidebar names the files when there's no text
            $this->actingAs($this->john)->getJson('/api/chat/conversations')
                ->assertJsonPath('data.0.lastMessage.text', '📎 photo.png, 📎 report.pdf');

            $url = $response->json('data.attachments.1.url');
            $this->assertStringContainsString('/api/chat/attachments/', $url);
            $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->get(strtok($url, '?'))->assertForbidden(); // without the signature

            // Deleting the message takes the file offline
            Chat::delete($this->conversation->messages()->first());
            $this->get($url)->assertNotFound();
        } finally {
            (new Filesystem)->deleteDirectory($root);
        }
    }

    public function test_disks_with_temporary_urls_serve_attachments_themselves(): void
    {
        Storage::fake('local'); // fake disks provide temporary URLs, like S3

        $message = Chat::send($this->conversation, $this->ann, '', [UploadedFile::fake()->create('a.pdf', 1)]);

        $this->actingAs($this->ann)->getJson($this->messagesUrl())
            ->assertJsonPath('data.0.attachments.0.url', fn (string $url) => ! str_contains($url, '/api/chat/attachments/'));
        Storage::disk('local')->assertExists($message->attachments()->first()->path);
    }

    public function test_gifs_only_come_from_the_providers_media_hosts(): void
    {
        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['gif' => $this->gif()])
            ->assertCreated()
            ->assertJsonPath('data.text', '')
            ->assertJsonPath('data.gif', $this->gif());

        $unsafe = ['https://evil.example/a.webp', 'http://static.klipy.com/a.webp', 'https://static.klipy.com@evil.example/a.webp', 'javascript:alert(1)'];
        foreach ($unsafe as $url) {
            $this->actingAs($this->ann)
                ->postJson($this->messagesUrl(), ['gif' => $this->gif(['image' => $url])])
                ->assertJsonValidationErrors('gif.image');
        }

        // No hosts, no GIFs
        config(['laravel-chat.gifs.media_hosts' => []]);
        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['gif' => $this->gif()])
            ->assertJsonValidationErrors(['gif.image', 'gif.still', 'gif.video']);
    }

    public function test_gifs_are_named_in_previews_and_replies_and_forwarded_with_their_message(): void
    {
        $gifMessage = Chat::send($this->conversation, $this->john, '', gif: $this->gif());
        $this->actingAs($this->ann)->getJson('/api/chat/conversations')->assertJsonPath('data.0.lastMessage.text', 'GIF');
        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['body' => 'Ha', 'replyToId' => (string) $gifMessage->id])
            ->assertJsonPath('data.replyTo.text', 'GIF');

        // vue-chat forwards a message's text, which a GIF doesn't have
        $elsewhere = $this->directBetween($this->ann, $this->person('Sara Lane'));
        $this->actingAs($this->ann)
            ->postJson("/api/chat/conversations/{$elsewhere->id}/messages", ['body' => '', 'forwardedFromId' => (string) $gifMessage->id])
            ->assertCreated()
            ->assertJsonPath('data.isForwarded', true)
            ->assertJsonPath('data.gif.id', 'hello-hi-662');

        // Something must still be sent
        $plain = Chat::send($this->conversation, $this->john, 'Text only');
        $this->actingAs($this->ann)
            ->postJson("/api/chat/conversations/{$elsewhere->id}/messages", ['body' => '', 'forwardedFromId' => (string) $plain->id])
            ->assertJsonValidationErrors('body');
    }

    /** @return array<string, mixed> */
    private function gif(array $overrides = []): array
    {
        return [
            'provider' => 'klipy',
            'id' => 'hello-hi-662',
            'title' => 'Hello',
            'width' => 498,
            'height' => 280,
            'video' => 'https://static.klipy.com/ii/935d7ab9/md.mp4',
            'image' => 'https://static.klipy.com/ii/935d7ab9/md.webp',
            'still' => 'https://static1.klipy.com/ii/935d7ab9/md.jpg',
            ...$overrides,
        ];
    }

    public function test_it_validates_messages(): void
    {
        config(['laravel-chat.attachments.max_files' => 1, 'laravel-chat.attachments.max_size_kb' => 100]);

        $this->actingAs($this->ann)->postJson($this->messagesUrl(), [])->assertJsonValidationErrors('body');
        $this->actingAs($this->ann)
            ->post($this->messagesUrl(), ['attachments' => [UploadedFile::fake()->create('a.pdf', 10), UploadedFile::fake()->create('b.pdf', 10)]], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('attachments');
        $this->actingAs($this->ann)
            ->post($this->messagesUrl(), ['attachments' => [UploadedFile::fake()->create('big.pdf', 500)]], ['Accept' => 'application/json'])
            ->assertJsonValidationErrors('attachments.0');
    }

    public function test_replies_quote_a_message_from_the_same_conversation(): void
    {
        $original = Chat::send($this->conversation, $this->john, 'Ready?');
        $elsewhere = Chat::send($this->directBetween($this->ann, $this->person('Sara Lane')), $this->ann, 'Other chat');

        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['body' => 'Yes', 'replyToId' => (string) $original->id])
            ->assertCreated()
            ->assertJsonPath('data.replyTo', ['messageId' => (string) $original->id, 'senderName' => 'John Doe', 'text' => 'Ready?']);

        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['body' => 'Yes', 'replyToId' => (string) $elsewhere->id])
            ->assertJsonValidationErrors('replyToId');

        // A deleted original keeps its sender but not its text
        Chat::delete($original);
        $this->actingAs($this->ann)->getJson($this->messagesUrl())
            ->assertJsonPath('data.0.replyTo', ['messageId' => (string) $original->id, 'senderName' => 'John Doe', 'text' => '']);
    }

    public function test_only_messages_you_can_see_can_be_forwarded(): void
    {
        $sara = $this->person('Sara Lane');
        $visible = Chat::send($this->directBetween($this->ann, $sara), $sara, 'FYI');
        $hidden = Chat::send($this->directBetween($this->john, $sara), $sara, 'Secret');

        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['body' => 'FYI', 'forwardedFromId' => (string) $visible->id])
            ->assertCreated()
            ->assertJsonPath('data.isForwarded', true);

        $this->actingAs($this->ann)
            ->postJson($this->messagesUrl(), ['body' => 'Secret', 'forwardedFromId' => (string) $hidden->id])
            ->assertJsonValidationErrors('forwardedFromId');
    }

    public function test_only_the_sender_edits_a_message(): void
    {
        $message = Chat::send($this->conversation, $this->ann, 'Helo');

        $this->actingAs($this->john)->patchJson("/api/chat/messages/{$message->id}", ['body' => 'Hacked'])->assertForbidden();
        $this->actingAs($this->person('Eve'))->patchJson("/api/chat/messages/{$message->id}", ['body' => 'Hacked'])->assertNotFound();

        $this->actingAs($this->ann)
            ->patchJson("/api/chat/messages/{$message->id}", ['body' => 'Hello'])
            ->assertOk()
            ->assertJsonPath('data.text', 'Hello')
            ->assertJsonPath('data.isEdited', true);
    }

    public function test_senders_and_admins_delete_messages(): void
    {
        $message = Chat::send($this->conversation, $this->ann, 'Oops');
        $this->actingAs($this->john)->deleteJson("/api/chat/messages/{$message->id}")->assertForbidden();
        $this->actingAs($this->ann)->deleteJson("/api/chat/messages/{$message->id}")->assertNoContent();
        $this->actingAs($this->ann)->getJson($this->messagesUrl())->assertJsonCount(0, 'data');
        $this->actingAs($this->ann)->deleteJson("/api/chat/messages/{$message->id}")->assertNotFound();

        // The group's creator is its admin
        $group = Chat::group($this->ann, [$this->john], 'Team');
        $johns = Chat::send($group, $this->john, 'Spam');
        $this->actingAs($this->ann)->deleteJson("/api/chat/messages/{$johns->id}")->assertNoContent();
    }

    public function test_reactions_are_grouped_by_emoji(): void
    {
        $message = Chat::send($this->conversation, $this->john, 'Shipped!');

        $this->actingAs($this->ann)->postJson("/api/chat/messages/{$message->id}/reactions", ['emoji' => '🎉'])->assertOk();
        $this->actingAs($this->john)->postJson("/api/chat/messages/{$message->id}/reactions", ['emoji' => '🎉'])->assertOk();
        $this->actingAs($this->ann)->postJson("/api/chat/messages/{$message->id}/reactions", ['emoji' => '👍'])
            ->assertJsonPath('data.reactions', [
                ['emoji' => '🎉', 'count' => 2, 'participantIds' => [(string) $this->ann->id, (string) $this->john->id]],
                ['emoji' => '👍', 'count' => 1, 'participantIds' => [(string) $this->ann->id]],
            ]);

        $this->actingAs($this->ann)
            ->deleteJson("/api/chat/messages/{$message->id}/reactions/".rawurlencode('🎉'))
            ->assertOk()
            ->assertJsonPath('data.reactions.0', ['emoji' => '🎉', 'count' => 1, 'participantIds' => [(string) $this->john->id]]);

        $this->actingAs($this->person('Eve'))
            ->postJson("/api/chat/messages/{$message->id}/reactions", ['emoji' => '👀'])
            ->assertNotFound();
    }

    public function test_members_pin_messages(): void
    {
        $message = Chat::send($this->conversation, $this->john, 'Plan');

        $this->actingAs($this->ann)->postJson("/api/chat/messages/{$message->id}/pin", ['pinned' => true])
            ->assertOk()
            ->assertJsonPath('data.isPinned', true);
        $this->actingAs($this->ann)->postJson("/api/chat/messages/{$message->id}/pin", ['pinned' => false])
            ->assertJsonPath('data.isPinned', false);
        $this->actingAs($this->person('Eve'))->postJson("/api/chat/messages/{$message->id}/pin")->assertNotFound();
    }
}
