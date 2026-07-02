<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamesUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_update_returns_401(): void
    {
        $game = Game::factory()->create();

        $this->putJson("/api/games/{$game->id}", [])->assertStatus(401);
    }

    public function test_authenticated_update_returns_200(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['title' => 'Old', 'status' => 'backlog']);

        $this->actingAs($user)
            ->putJson("/api/games/{$game->id}", ['title' => 'New', 'status' => 'playing'])
            ->assertOk()
            ->assertJsonPath('title', 'New')
            ->assertJsonPath('status', 'playing')
            ->assertJsonMissingPath('user_id');
    }

    public function test_update_cross_user_game_returns_404_not_403(): void
    {
        $user = User::factory()->create();
        $otherGame = Game::factory()->create();

        $this->actingAs($user)
            ->putJson("/api/games/{$otherGame->id}", ['title' => 'Nope'])
            ->assertNotFound();
    }

    public function test_update_validation_rejects_bad_status_422(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->putJson("/api/games/{$game->id}", ['status' => 'paused'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_update_ignores_source_in_body(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['source' => 'manual']);

        $this->actingAs($user)
            ->putJson("/api/games/{$game->id}", ['source' => 'steam', 'title' => 'Still Manual'])
            ->assertOk()
            ->assertJsonPath('source', 'manual');
    }
}
