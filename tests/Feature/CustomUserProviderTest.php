<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Contracts\ChatUserProvider;
use Pvngu\Chat\Events\ConversationJoined;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\Fixtures\HashedIdUserProvider;
use Pvngu\Chat\Tests\TestCase;

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
