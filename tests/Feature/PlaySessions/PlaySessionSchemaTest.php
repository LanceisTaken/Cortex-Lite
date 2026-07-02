<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PlaySessionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_play_sessions_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('play_sessions'));
        $this->assertTrue(Schema::hasColumns('play_sessions', [
            'id', 'user_id', 'game_id',
            'started_at', 'ended_at', 'duration_seconds',
            'created_at', 'updated_at',
        ]));
    }

    public function test_user_has_many_play_sessions(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->create();
        PlaySession::factory()->for($user)->for($game)->create();

        $this->assertCount(2, $user->fresh()->playSessions);
    }

    public function test_game_has_many_play_sessions(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->for($user)->for($game)->create();

        $this->assertCount(1, $game->fresh()->playSessions);
    }

    public function test_deleting_user_cascades_play_sessions(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        $session = PlaySession::factory()->for($user)->for($game)->create();

        $user->delete();

        $this->assertDatabaseMissing('play_sessions', ['id' => $session->id]);
    }
}
