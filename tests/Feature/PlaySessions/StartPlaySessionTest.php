<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StartPlaySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_start_returns_401(): void
    {
        $this->postJson('/api/sessions/start', ['game_id' => 1])->assertStatus(401);
    }

    public function test_authenticated_start_creates_open_session_201(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => $game->id])
            ->assertCreated()
            ->assertJsonPath('game_id', $game->id)
            ->assertJsonMissingPath('user_id')
            ->assertJsonPath('ended_at', null)
            ->assertJsonPath('duration_seconds', null);

        $this->assertDatabaseHas('play_sessions', [
            'user_id' => $user->id,
            'game_id' => $game->id,
            'ended_at' => null,
        ]);
    }

    public function test_start_missing_game_id_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_id');
    }

    public function test_start_with_nonexistent_game_returns_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_id');
    }

    public function test_start_with_another_users_game_returns_404_idor(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersGame = Game::factory()->for($other)->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => $othersGame->id])
            ->assertStatus(404);

        $this->assertDatabaseMissing('play_sessions', [
            'user_id' => $user->id,
            'game_id' => $othersGame->id,
        ]);
    }

    public function test_start_when_user_already_has_an_open_session_returns_409(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->active()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', ['game_id' => $game->id])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'play_session_already_active');

        $this->assertSame(1, $user->fresh()->playSessions()->whereNull('ended_at')->count());
    }

    public function test_start_ignores_user_id_in_body(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/start', [
                'game_id' => $game->id,
                'user_id' => $other->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('play_sessions', ['user_id' => $user->id, 'game_id' => $game->id]);
        $this->assertDatabaseMissing('play_sessions', ['user_id' => $other->id]);
    }
}
