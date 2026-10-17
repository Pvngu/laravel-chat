<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Events\ConversationJoined;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\Fixtures\HashedIdUserProvider;
use Pvngu\Chat\Tests\TestCase;
use Pvngu\Chat\Users\DefaultChatUserProvider;

class CustomUserProviderTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-chat.user_provider', HashedIdUserProvider::class);
    }

    public function test_public_ids_are_used_everywhere_instead_of_database_keys(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $users = app(ChatUserProvider::class);
        [$annId, $johnId, $saraId] = [$users->publicId($ann), $users->publicId($john), $users->publicId($sara)];

        $conversationId = $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [$johnId, $saraId], 'name' => 'Team'])
            ->assertCreated()
            ->assertJsonPath('data.participants.*.id', [$annId, $johnId, $saraId])
            ->json('data.id');

        $this->actingAs($ann)
            ->postJson("/api/chat/conversations/$conversationId/messages", ['body' => 'Hi'])
            ->assertJsonPath('data.senderId', $annId);
        $this->actingAs($ann)->deleteJson("/api/chat/conversations/$conversationId/members/$saraId")->assertNoContent();
        $this->actingAs($ann)->getJson('/api/chat/users')->assertJsonPath('data.*.id', [$johnId, $saraId]);

        // The raw database key isn't an id the API knows
        $this->actingAs($ann)
            ->postJson('/api/chat/conversations', ['participantIds' => [(string) $john->id], 'isDirect' => true])
            ->assertJsonValidationErrors('participantIds');
    }

    public function test_people_whose_accounts_are_gone_keep_their_public_id(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $users = app(ChatUserProvider::class);
        [$johnId, $saraId] = [$users->publicId($john), $users->publicId($sara)];
        $group = Chat::group($ann, [$john, $sara], 'Team');
        $message = Chat::send($group, $john, "Hi <@$saraId>");
        Chat::react($message, $sara, '👍');
        Chat::removeMember($group, $sara, by: $ann);
        [$johnKey, $saraKey] = [(string) $john->getKey(), (string) $sara->getKey()];

        // Their messages, reactions and mentions stay; their accounts don't
        $john->delete();
        $sara->delete();

        $messages = collect($this->actingAs($ann)->getJson("/api/chat/conversations/{$group->id}/messages")->assertOk()->json('data'));
        $sent = $messages->firstWhere('id', (string) $message->id);
        $this->assertSame($johnId, $sent['senderId']);
        $this->assertSame([$saraId], $sent['reactions'][0]['participantIds']);
        $this->assertSame([['id' => $saraId, 'name' => '']], $sent['mentions']);
        $this->assertSame($saraId, $messages->firstWhere('event.type', 'removed')['event']['user']['id']);
        $this->actingAs($ann)->getJson("/api/chat/conversations/{$group->id}")
            ->assertJsonPath('data.participants.*.id', [$users->publicId($ann), $johnId])
            ->assertJsonPath('data.participants.1.name', '');

        // Never the database key; an app showing its keys keeps showing them
        $this->assertNotContains($johnKey, [$sent['senderId'], ...$sent['reactions'][0]['participantIds']]);
        $this->assertNotContains($saraKey, [$sent['senderId'], ...$sent['reactions'][0]['participantIds']]);
        $this->assertSame('42', (new DefaultChatUserProvider)->publicIdForKey(42));
    }

    public function test_user_channels_are_named_by_public_id(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        Event::fake([ConversationJoined::class]);

        Chat::direct($ann, $john);

        $publicId = app(ChatUserProvider::class)->publicId($john);
        Event::assertDispatched(ConversationJoined::class, fn ($event) => $event->broadcastOn()[0]->name === "private-chat.user.$publicId");
    }
}
