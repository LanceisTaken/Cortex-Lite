<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamesDestroyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_destroy_returns_401(): void
    {
        $game = Game::factory()->create();

        $this->deleteJson("/api/games/{$game->id}")->assertStatus(401);
    }

    public function test_authenticated_destroy_returns_204(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->deleteJson("/api/games/{$game->id}")
            ->assertNoContent();

        $this->assertDatabaseMissing('games', ['id' => $game->id]);
    }

    public function test_destroy_cross_user_game_returns_404_not_403(): void
    {
        $user = User::factory()->create();
        $otherGame = Game::factory()->create();

        $this->actingAs($user)
            ->deleteJson("/api/games/{$otherGame->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('games', ['id' => $otherGame->id]);
    }
}
