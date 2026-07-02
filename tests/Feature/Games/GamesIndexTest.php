<?php

namespace Tests\Feature\Games;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamesIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_index_returns_401(): void
    {
        $this->getJson('/api/games')->assertStatus(401);
    }

    public function test_authenticated_index_returns_only_own_games(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        Game::factory()->for($user)->create(['title' => 'Owned Game']);
        Game::factory()->for($other)->create(['title' => 'Other Game']);

        $this->actingAs($user)
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Owned Game');
    }

    public function test_index_paginates_with_default_15_per_page(): void
    {
        $user = User::factory()->create();
        Game::factory()->count(16)->for($user)->create();

        $this->actingAs($user)
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonCount(15, 'data')
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16);
    }

    public function test_index_filters_by_status(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create(['title' => 'Currently Playing', 'status' => 'playing']);
        Game::factory()->for($user)->create(['title' => 'Finished', 'status' => 'completed']);

        $this->actingAs($user)
            ->getJson('/api/games?status=completed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Finished');
    }

    public function test_index_searches_by_title_case_insensitive(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create(['title' => 'Cyber Runner']);
        Game::factory()->for($user)->create(['title' => 'Puzzle Box']);

        $this->actingAs($user)
            ->getJson('/api/games?search=cyber')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', 'Cyber Runner');
    }

    public function test_index_escapes_sql_wildcards_in_search(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create(['title' => '50%_off']);
        Game::factory()->for($user)->create(['title' => '50XXoff']);

        $this->actingAs($user)
            ->getJson('/api/games?'.http_build_query(['search' => '50%_off']))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.title', '50%_off');
    }

    public function test_index_sorts_by_last_played_desc_by_default(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create(['title' => 'Older', 'last_played_at' => now()->subDays(3)]);
        Game::factory()->for($user)->create(['title' => 'Newer', 'last_played_at' => now()]);

        $this->actingAs($user)
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Newer');
    }

    public function test_index_sorts_by_title_asc_when_requested(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create(['title' => 'Zed']);
        Game::factory()->for($user)->create(['title' => 'Alpha']);

        $this->actingAs($user)
            ->getJson('/api/games?sort=title_asc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Alpha');
    }

    public function test_index_sorts_by_playtime_desc_when_requested(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create(['title' => 'Short', 'playtime_minutes' => 10]);
        Game::factory()->for($user)->create(['title' => 'Long', 'playtime_minutes' => 500]);

        $this->actingAs($user)
            ->getJson('/api/games?sort=playtime_desc')
            ->assertOk()
            ->assertJsonPath('data.0.title', 'Long');
    }

    public function test_index_rejects_invalid_sort_param_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/games?sort=weird')
            ->assertStatus(422);
    }

    public function test_index_response_hides_user_id_field(): void
    {
        $user = User::factory()->create();
        Game::factory()->for($user)->create();

        $this->actingAs($user)
            ->getJson('/api/games')
            ->assertOk()
            ->assertJsonMissingPath('data.0.user_id');
    }
}
