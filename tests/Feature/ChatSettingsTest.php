<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Support\Facades\Event;
use Pvngu\Chat\Events\ConversationUpdated;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\TestCase;

class ChatSettingsTest extends TestCase
{
    public function test_admins_change_a_channels_name_description_and_privacy(): void
    {
        Event::fake([ConversationUpdated::class]);
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $channel = Chat::channel($ann, 'general');
        Chat::addParticipant($channel, $john);
        Chat::channel($ann, 'random');
        $url = "/api/chat/channels/{$channel->id}";

        $this->actingAs($ann)
            ->patchJson($url, ['name' => 'announcements', 'description' => 'Company news', 'isPrivate' => true])
            ->assertOk()
            ->assertJsonPath('data.name', 'announcements')
            ->assertJsonPath('data.description', 'Company news')
            ->assertJsonPath('data.isPrivate', true)
            ->assertJsonPath('data.isAdmin', true);
        Event::assertDispatched(ConversationUpdated::class, fn (ConversationUpdated $event) => $event->broadcastWith() === [
            'kind' => 'channel',
            'conversationId' => $channel->id,
            'name' => 'announcements',
            'description' => 'Company news',
            'isPrivate' => true,
        ]);

        $this->actingAs($ann)->patchJson($url, ['name' => 'random'])->assertJsonValidationErrors('name');
        $this->actingAs($ann)->patchJson($url, ['name' => ''])->assertJsonValidationErrors('name');
        $this->actingAs($john)->patchJson($url, ['name' => 'mine'])->assertForbidden();
        $this->actingAs($this->person('Eve'))->patchJson($url, ['name' => 'mine'])->assertNotFound();
    }

    public function test_groups_can_be_renamed_but_direct_messages_have_no_settings(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $group = Chat::group($ann, [$john], 'Launch');

        $this->actingAs($ann)
            ->patchJson("/api/chat/conversations/{$group->id}", ['name' => 'Launch v2', 'isPrivate' => false])
            ->assertOk()
            ->assertJsonPath('data.title', 'Launch v2')
            ->assertJsonPath('data.isPrivate', true); // groups stay private

        $direct = $this->directBetween($ann, $john);
        $this->actingAs($ann)->patchJson("/api/chat/conversations/{$direct->id}", ['name' => 'Us'])->assertForbidden();
    }

    public function test_the_details_say_who_created_it_and_whether_you_administer_it(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $channel = Chat::channel($ann, 'general', description: 'Everything');
        Chat::addParticipant($channel, $john);

        $this->actingAs($john)->getJson("/api/chat/channels/{$channel->id}")
            ->assertJsonPath('data.isAdmin', false)
            ->assertJsonPath('data.createdBy.name', 'Ann Smith')
            ->assertJsonPath('data.createdAt', $channel->created_at->toIso8601ZuluString());
    }
}
