<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivePlaySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_active_returns_401(): void
    {
        $this->getJson('/api/sessions/active')->assertStatus(401);
    }

    public function test_active_returns_null_when_no_open_session(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->create();

        $this->actingAs($user)
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data', null);
    }

    public function test_active_returns_the_open_session_with_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['title' => 'Portal 2']);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create();

        $this->actingAs($user)
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data.id', $session->id)
            ->assertJsonPath('data.ended_at', null)
            ->assertJsonPath('data.game.id', $game->id)
            ->assertJsonPath('data.game.title', 'Portal 2')
            ->assertJsonMissingPath('data.user_id');
    }

    public function test_active_is_scoped_to_the_authenticated_user(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersGame = Game::factory()->for($other)->create();
        PlaySession::factory()->for($other)->for($othersGame)->active()->create();

        $this->actingAs($user)
            ->getJson('/api/sessions/active')
            ->assertOk()
            ->assertJsonPath('data', null);
    }
}
