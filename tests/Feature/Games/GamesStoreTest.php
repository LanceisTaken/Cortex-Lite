<?php

namespace Tests\Feature\Games;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GamesStoreTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_store_returns_401(): void
    {
        $this->postJson('/api/games', [])->assertStatus(401);
    }

    public function test_authenticated_store_creates_game_201(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/games', [
                'title' => 'Control',
                'platform' => 'PC',
                'genre' => 'Action',
                'status' => 'playing',
                'playtime_minutes' => 90,
                'last_played_at' => '2026-07-02T10:00:00Z',
                'steam_app_id' => 870780,
                'cover_url' => 'https://example.com/control.jpg',
            ])
            ->assertCreated()
            ->assertJsonPath('title', 'Control')
            ->assertJsonPath('source', 'manual')
            ->assertJsonMissingPath('user_id');

        $this->assertDatabaseHas('games', [
            'user_id' => $user->id,
            'title' => 'Control',
            'source' => 'manual',
        ]);
    }

    public function test_store_validation_rejects_missing_title_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/games', ['status' => 'playing'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('title');
    }

    public function test_store_validation_rejects_invalid_status_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/games', ['title' => 'Uppercase', 'status' => 'PLAYING'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');
    }

    public function test_store_ignores_user_id_in_body(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/games', [
                'title' => 'Mine',
                'status' => 'backlog',
                'user_id' => $other->id,
            ])
            ->assertCreated();

        $this->assertDatabaseHas('games', ['user_id' => $user->id, 'title' => 'Mine']);
        $this->assertDatabaseMissing('games', ['user_id' => $other->id, 'title' => 'Mine']);
    }

    public function test_store_ignores_id_in_body(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/games', [
                'id' => 9999,
                'title' => 'New Id',
                'status' => 'backlog',
            ])
            ->assertCreated()
            ->assertJsonPath('id', 1);
    }

    public function test_store_ignores_source_in_body_sets_manual(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/games', [
                'title' => 'Manual Entry',
                'status' => 'playing',
                'source' => 'steam',
            ])
            ->assertCreated()
            ->assertJsonPath('source', 'manual');
    }
}
