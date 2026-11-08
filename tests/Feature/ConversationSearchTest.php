<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Facades\Chat;
use Pvngu\Chat\Tests\Fixtures\User;
use Pvngu\Chat\Tests\TestCase;

class ConversationSearchTest extends TestCase
{
    private User $ann;

    /** @return list<int> */
    private function search(string $term): array
    {
        $response = $this->actingAs($this->ann)->getJson('/api/chat/conversations?search='.urlencode($term))->assertOk();

        return collect($response->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_it_finds_conversations_by_their_name_or_someone_else_in_them(): void
    {
        $this->ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $sara = $this->person('Sara Lane');

        $withJohn = $this->directBetween($this->ann, $john);
        $withSara = $this->directBetween($this->ann, $sara);
        $design = Chat::group($this->ann, [$john, $sara], 'Design Team');
        $this->directBetween($john, $sara); // not Ann's

        $this->assertSame([$design->id], $this->search('design'));
        $this->assertSame([$withJohn->id, $design->id], $this->search('JOHN')); // any case
        $this->assertSame([$withSara->id, $design->id], $this->search('lane'));
        // Ann is in every conversation: her own name doesn't match them all
        $this->assertSame([], $this->search('Ann Smith'));
        // Blank searches list everything
        $this->assertCount(3, $this->search('   '));
    }

    public function test_percent_and_underscore_match_themselves(): void
    {
        $this->ann = $this->person('Ann Smith');
        $team = Chat::group($this->ann, [$this->person('John Doe')], 'Sales 50% off');
        Chat::group($this->ann, [$this->person('Sara Lane')], 'Sales 500 club');
        Chat::group($this->ann, [$this->person('Kevin Park')], 'team_alpha');

        $this->assertSame([$team->id], $this->search('50%'));
        $this->assertCount(1, $this->search('team_'));
        $this->assertSame([], $this->search('team%alpha'));
    }

    public function test_the_next_page_keeps_the_search(): void
    {
        config(['laravel-chat.pagination.conversations' => 1]);
        $this->ann = $this->person('Ann Smith');
        Chat::group($this->ann, [$this->person('John Doe')], 'Design A');
        Chat::group($this->ann, [$this->person('Sara Lane')], 'Design B');

        $next = $this->actingAs($this->ann)->getJson('/api/chat/conversations?search=design')->json('links.next');
        $this->assertStringContainsString('search=design', (string) $next);
    }

    public function test_a_name_that_isnt_a_column_only_matches_conversation_names(): void
    {
        config(['laravel-chat.user_attributes.name' => 'nickname']); // an accessor, say: the database can't search it
        $this->ann = $this->person('Ann Smith');
        $john = $this->person('John Doe');
        $this->directBetween($this->ann, $john);
        $group = Chat::group($this->ann, [$john], 'John fans');

        $this->assertSame([$group->id], $this->search('john'));
    }
}
