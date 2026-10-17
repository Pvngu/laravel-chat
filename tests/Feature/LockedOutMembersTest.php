<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\Fixtures\LockedOutUserProvider;
use Pvngu\Chat\Tests\TestCase;

// Members the app no longer lets chat (disabled, lost access) stay in their chats until someone removes them
class LockedOutMembersTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('laravel-chat.user_provider', LockedOutUserProvider::class);
    }

    protected function tearDown(): void
    {
        LockedOutUserProvider::$lockedOut = [];

        parent::tearDown();
    }

    public function test_admins_still_remove_and_demote_them_but_dont_promote_them(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $channel = Chat::channel($ann, 'leadership', isPrivate: true);
        Chat::addParticipant($channel, $john);
        Chat::addParticipant($channel, $sara);
        Chat::setRole($channel, $john, 'admin');
        LockedOutUserProvider::$lockedOut = [(string) $john->id, (string) $sara->id];
        $url = "/api/chat/channels/{$channel->id}/members";

        $this->actingAs($ann)->patchJson("$url/{$sara->id}", ['role' => 'admin'])->assertJsonValidationErrors('userId');
        $this->actingAs($ann)->patchJson("$url/{$john->id}", ['role' => 'member'])->assertNoContent();
        $this->actingAs($ann)->deleteJson("$url/{$sara->id}")->assertNoContent();

        $this->actingAs($ann)->getJson("/api/chat/channels/{$channel->id}")
            ->assertJsonPath('data.members.*.id', [(string) $ann->id, (string) $john->id])
            ->assertJsonPath('data.members.*.role', ['admin', 'member']);
        $lines = $this->actingAs($ann)->getJson("/api/chat/channels/{$channel->id}/messages")->json('data');
        $this->assertSame(
            [['promoted', 'John Doe'], ['demoted', 'John Doe'], ['removed', 'Sara Lane']],
            array_map(fn (array $line) => [$line['event']['type'], $line['event']['user']['name']], $lines),
        );
    }

    public function test_someone_outside_the_chat_learns_nothing_about_who_is_in_it(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $channel = Chat::channel($ann, 'leadership', isPrivate: true);
        Chat::addParticipant($channel, $john);
        LockedOutUserProvider::$lockedOut = [(string) $john->id];
        $url = "/api/chat/channels/{$channel->id}/members";

        $eve = $this->person('Eve');
        $this->actingAs($eve)->deleteJson("$url/{$john->id}")->assertNotFound();
        $this->actingAs($eve)->deleteJson("$url/12345")->assertNotFound();
        $this->actingAs($ann)->deleteJson("$url/12345")->assertJsonValidationErrors('userId');
    }
}
