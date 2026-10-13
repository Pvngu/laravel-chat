<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class AdminRolesTest extends TestCase
{
    public function test_admins_make_people_admins_and_members_again(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $channel = Chat::channel($ann, 'leadership', isPrivate: true);
        Chat::addParticipant($channel, $john);
        Chat::addParticipant($channel, $sara);
        $url = "/api/chat/channels/{$channel->id}/members";

        $this->actingAs($john)->patchJson("$url/{$sara->id}", ['role' => 'admin'])->assertForbidden();
        $this->actingAs($ann)->patchJson("$url/{$john->id}", ['role' => 'admin'])->assertNoContent();
        $this->actingAs($ann)->getJson("/api/chat/channels/{$channel->id}")
            ->assertJsonPath('data.members.*.role', ['admin', 'admin', 'member']);

        // John, now an admin, can add people and change roles, and step down himself
        $this->actingAs($john)->postJson($url, ['userId' => (string) $this->person('Luis Ortega')->id])->assertNoContent();
        $this->actingAs($john)->patchJson("$url/{$sara->id}", ['role' => 'admin'])->assertNoContent();
        $this->actingAs($john)->patchJson("$url/{$john->id}", ['role' => 'member'])->assertNoContent();

        $this->actingAs($sara)->patchJson("$url/{$sara->id}", ['role' => 'owner'])->assertJsonValidationErrors('role');
        $this->actingAs($sara)->patchJson("$url/{$this->person('Eve')->id}", ['role' => 'admin'])->assertJsonValidationErrors('userId');

        $lines = $this->actingAs($john)->getJson("/api/chat/channels/{$channel->id}/messages")->json('data');
        $this->assertSame(
            [['promoted', 'John Doe'], ['added', 'Luis Ortega'], ['promoted', 'Sara Lane'], ['demoted', 'John Doe']],
            array_map(fn (array $line) => [$line['event']['type'], $line['event']['user']['name']], $lines),
        );
    }

    public function test_the_last_admin_leaving_hands_over_to_who_they_choose(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $channel = Chat::channel($ann, 'leadership', isPrivate: true);
        Chat::addParticipant($channel, $john);
        Chat::addParticipant($channel, $sara);
        $url = "/api/chat/channels/{$channel->id}/members/me";

        $this->actingAs($ann)->deleteJson($url, ['successorId' => (string) $this->person('Eve')->id])->assertJsonValidationErrors('successorId');
        $this->actingAs($ann)->deleteJson($url, ['successorId' => (string) $sara->id])->assertNoContent();

        $this->actingAs($john)->getJson("/api/chat/channels/{$channel->id}")
            ->assertJsonPath('data.members.*.role', ['member', 'admin'])
            ->assertJsonPath('data.isAdmin', false);
        $lines = $this->actingAs($sara)->getJson("/api/chat/channels/{$channel->id}/messages")->json('data');
        $this->assertSame([['Ann Smith', 'promoted', 'Sara Lane'], ['Ann Smith', 'left', 'Ann Smith']], array_map(
            fn (array $line) => [$this->nameOf($line['senderId']), $line['event']['type'], $line['event']['user']['name']],
            $lines,
        ));
    }

    public function test_otherwise_whoever_has_been_in_it_longest_takes_over(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');
        $group = Chat::group($ann, [$sara, $john]); // Sara joined before John

        Chat::removeMember($group, $ann);
        $this->actingAs($sara)->getJson("/api/chat/conversations/{$group->id}")
            ->assertJsonPath('data.participants.*.name', ['Sara Lane', 'John Doe'])
            ->assertJsonPath('data.participants.*.role', ['admin', 'member']);

        // With another admin left, nobody is promoted
        Chat::setRole($group, $john, 'admin', by: $sara);
        Chat::removeMember($group, $sara);
        $this->actingAs($john)->getJson("/api/chat/conversations/{$group->id}")->assertJsonPath('data.participants.*.role', ['admin']);
    }

    public function test_only_private_channels_keep_adding_people_to_admins(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $public = Chat::channel($ann, 'general');
        $group = Chat::group($ann, [$john]);
        Chat::addParticipant($public, $john);

        $this->actingAs($john)->postJson("/api/chat/channels/{$public->id}/members", ['userId' => (string) $this->person('Sara')->id])->assertNoContent();
        $this->actingAs($john)->postJson("/api/chat/conversations/{$group->id}/members", ['userId' => (string) $this->person('Luis')->id])->assertNoContent();
    }

    public function test_the_creator_stays_an_admin_that_other_admins_cant_remove(): void
    {
        $ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $channel = Chat::channel($ann, 'leadership', isPrivate: true);
        Chat::addParticipant($channel, $john);
        Chat::setRole($channel, $john, 'admin', by: $ann);
        $url = "/api/chat/channels/{$channel->id}/members";

        $this->actingAs($john)->patchJson("$url/{$ann->id}", ['role' => 'member'])->assertForbidden();
        $this->actingAs($john)->deleteJson("$url/{$ann->id}")->assertForbidden();
        $this->actingAs($ann)->patchJson("$url/{$ann->id}", ['role' => 'member'])->assertForbidden();
        $this->actingAs($this->person('Eve'))->patchJson("$url/{$ann->id}", ['role' => 'member'])->assertNotFound();

        // Leaving is always theirs to do, and coming back makes them an admin again
        $this->actingAs($ann)->deleteJson("$url/me")->assertNoContent();
        $this->actingAs($john)->postJson($url, ['userId' => (string) $ann->id])->assertNoContent();
        $this->actingAs($john)->getJson("/api/chat/channels/{$channel->id}")
            ->assertJsonPath('data.members.*.name', ['John Doe', 'Ann Smith'])
            ->assertJsonPath('data.members.*.role', ['admin', 'admin']);
    }

    private function nameOf(string $id): string
    {
        return User::query()->findOrFail($id)->name;
    }
}
