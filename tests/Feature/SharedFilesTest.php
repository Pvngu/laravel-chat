<?php

namespace Pvngu\Chat\Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Models\Conversation;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

// A chat's Files tab: its photos and videos, and its other files
class SharedFilesTest extends TestCase
{
    private User $ann;

    private User $john;

    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->ann = $this->person('Ann Smith');
        $this->john = $this->person('John Doe');
        $this->conversation = $this->directBetween($this->ann, $this->john);
    }

    private function filesUrl(string $query = '', ?Conversation $conversation = null): string
    {
        return '/api/chat/conversations/'.($conversation ?? $this->conversation)->id.'/files'.$query;
    }

    public function test_it_lists_photos_and_videos_apart_from_other_files_newest_first(): void
    {
        Carbon::setTestNow('2026-03-10 09:00:00');
        $first = Chat::send($this->conversation, $this->john, 'Look', [
            UploadedFile::fake()->image('beach.png', 20, 20),
            UploadedFile::fake()->create('notes.pdf', 3, 'application/pdf'),
        ]);
        Carbon::setTestNow('2026-03-10 09:05:00');
        Chat::send($this->conversation, $this->ann, '', [
            UploadedFile::fake()->create('clip.mp4', 5, 'video/mp4'),
            UploadedFile::fake()->create('budget.csv', 1, 'text/csv'),
        ]);
        Chat::send($this->conversation, $this->ann, 'No files here');

        $media = $this->actingAs($this->ann)->getJson($this->filesUrl('?type=media'))->assertOk();
        $this->assertSame(['clip.mp4', 'beach.png'], array_column($media->json('data'), 'name'));
        $this->assertNull($media->json('links.next'));
        $media->assertJsonPath('data.1', fn (array $file) => $file['isImage'] === true
            && $file['type'] === 'image/png'
            && $file['messageId'] === (string) $first->id
            && $file['sender']['id'] === (string) $this->john->id
            && $file['sender']['name'] === 'John Doe'
            && $file['sentAt'] === '2026-03-10T09:00:00Z'
            && $file['url'] !== '');

        $files = $this->actingAs($this->ann)->getJson($this->filesUrl('?type=files'))->assertOk();
        $this->assertSame(['budget.csv', 'notes.pdf'], array_column($files->json('data'), 'name'));

        // Without a type, everything
        $this->assertCount(4, $this->actingAs($this->ann)->getJson($this->filesUrl())->json('data'));
    }

    public function test_it_pages_back_from_the_last_file_shown(): void
    {
        config(['laravel-chat.pagination.files' => 2]);
        foreach (['a', 'b', 'c'] as $name) {
            Chat::send($this->conversation, $this->john, '', [UploadedFile::fake()->create("$name.pdf", 1, 'application/pdf')]);
        }

        $page = $this->actingAs($this->ann)->getJson($this->filesUrl('?type=files'))->assertOk();
        $this->assertSame(['c.pdf', 'b.pdf'], array_column($page->json('data'), 'name'));
        $this->assertNotNull($page->json('links.next'));

        // A file sent meanwhile doesn't shift what comes next
        Chat::send($this->conversation, $this->ann, '', [UploadedFile::fake()->create('d.pdf', 1, 'application/pdf')]);
        $before = $page->json('data.1.id');
        $next = $this->actingAs($this->ann)->getJson($this->filesUrl("?type=files&before=$before"))->assertOk();
        $this->assertSame(['a.pdf'], array_column($next->json('data'), 'name'));
        $this->assertNull($next->json('links.next'));
    }

    public function test_files_of_deleted_messages_and_other_chats_are_left_out(): void
    {
        $deleted = Chat::send($this->conversation, $this->john, '', [UploadedFile::fake()->image('gone.png')]);
        Chat::send($this->conversation, $this->john, '', [UploadedFile::fake()->image('kept.png')]);
        Chat::delete($deleted);
        $elsewhere = $this->directBetween($this->ann, $this->person('Sara Lane'));
        Chat::send($elsewhere, $this->ann, '', [UploadedFile::fake()->image('elsewhere.png')]);

        $this->assertSame(
            ['kept.png'],
            array_column($this->actingAs($this->ann)->getJson($this->filesUrl('?type=media'))->json('data'), 'name'),
        );
    }

    public function test_only_people_who_can_see_the_chat_see_its_files(): void
    {
        Chat::send($this->conversation, $this->john, '', [UploadedFile::fake()->image('private.png')]);
        $this->actingAs($this->person('Sara Lane'))->getJson($this->filesUrl())->assertNotFound();
        $this->actingAs($this->person('Other Team', 'globex'))->getJson($this->filesUrl())->assertNotFound();

        // Anyone in the team sees a public channel's
        $channel = Chat::channel($this->john, 'general');
        Chat::send($channel, $this->john, '', [UploadedFile::fake()->image('team.png')]);
        $this->actingAs($this->person('Luis Ortega'))
            ->getJson("/api/chat/channels/{$channel->id}/files")
            ->assertOk()
            ->assertJsonPath('data.0.name', 'team.png');

        $this->actingAs($this->ann)->getJson($this->filesUrl('?type=everything'))->assertJsonValidationErrors('type');
        $this->actingAs($this->ann)->getJson($this->filesUrl('?before=abc'))->assertJsonValidationErrors('before');
    }

    public function test_listing_files_does_not_query_per_file(): void
    {
        $count = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->ann)->getJson($this->filesUrl())->assertOk();

            return count(DB::getQueryLog());
        };

        Chat::send($this->conversation, $this->john, '', [UploadedFile::fake()->image('one.png')]);
        $few = $count();
        foreach (range(1, 4) as $i) {
            $sender = $i % 2 ? $this->ann : $this->john;
            Chat::send($this->conversation, $sender, '', [UploadedFile::fake()->image("$i.png"), UploadedFile::fake()->create("$i.pdf", 1)]);
        }

        $this->assertSame($few, $count());
    }
}
