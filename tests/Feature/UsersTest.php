<?php

namespace Pvngu\Chat\Tests\Feature;

use Pvngu\Chat\Tests\TestCase;

class UsersTest extends TestCase
{
    public function test_it_lists_the_other_people_in_your_tenant_by_name(): void
    {
        $ann = $this->person('Ann Smith');
        $this->person('Zoe');
        $this->person('Bob');
        $this->person('Olga', 'globex');
        $avatar = $this->person('Cy');
        $avatar->forceFill(['avatar_url' => 'https://example.test/cy.png'])->save();

        $this->actingAs($ann)->getJson('/api/chat/users')
            ->assertOk()
            ->assertJsonPath('data.*.name', ['Bob', 'Cy', 'Zoe'])
            ->assertJsonPath('data.1.avatarUrl', 'https://example.test/cy.png');
    }
}
