<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EndPlaySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_end_returns_401(): void
    {
        $this->postJson('/api/sessions/1/end')->assertStatus(401);
    }

    public function test_end_computes_duration_and_returns_200(): void
    {
        Carbon::setTestNow('2026-07-02 12:00:00');
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['source' => 'manual', 'playtime_minutes' => 0]);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create([
            'started_at' => now()->subMinutes(45),
        ]);

        $this->actingAs($user)
            ->postJson("/api/sessions/{$session->id}/end")
            ->assertOk()
            ->assertJsonPath('id', $session->id)
            ->assertJsonPath('duration_seconds', 45 * 60)
            ->assertJsonMissingPath('user_id');

        $this->assertDatabaseHas('play_sessions', [
            'id' => $session->id,
            'duration_seconds' => 45 * 60,
        ]);

        Carbon::setTestNow();
    }

    public function test_end_increments_playtime_minutes_for_manual_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['source' => 'manual', 'playtime_minutes' => 100]);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create([
            'started_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)->postJson("/api/sessions/{$session->id}/end")->assertOk();

        $this->assertSame(130, $game->fresh()->playtime_minutes);
        $this->assertNotNull($game->fresh()->last_played_at);
    }

    public function test_end_does_not_increment_playtime_minutes_for_steam_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create([
            'source' => 'steam',
            'steam_app_id' => 620,
            'playtime_minutes' => 500,
        ]);
        $session = PlaySession::factory()->for($user)->for($game)->active()->create([
            'started_at' => now()->subMinutes(30),
        ]);

        $this->actingAs($user)->postJson("/api/sessions/{$session->id}/end")->assertOk();

        $this->assertSame(500, $game->fresh()->playtime_minutes);
        $this->assertNotNull($game->fresh()->last_played_at);
        $this->assertNotNull(PlaySession::find($session->id)->ended_at);
    }

    public function test_end_with_another_users_session_returns_404_idor(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $othersGame = Game::factory()->for($other)->create();
        $othersSession = PlaySession::factory()->for($other)->for($othersGame)->active()->create();

        $this->actingAs($user)
            ->postJson("/api/sessions/{$othersSession->id}/end")
            ->assertStatus(404);

        $this->assertNull(PlaySession::find($othersSession->id)->ended_at);
    }

    public function test_ending_an_already_ended_session_returns_409(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        $session = PlaySession::factory()->for($user)->for($game)->create();

        $this->actingAs($user)
            ->postJson("/api/sessions/{$session->id}/end")
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'play_session_already_ended');
    }

    public function test_ending_nonexistent_session_returns_404(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sessions/999999/end')
            ->assertStatus(404);
    }
}
