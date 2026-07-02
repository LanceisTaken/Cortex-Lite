<?php

namespace Tests\Feature\PlaySessions;

use App\Models\Game;
use App\Models\PlaySession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlaySessionHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_history_returns_401(): void
    {
        $this->getJson('/api/sessions')->assertStatus(401);
    }

    public function test_history_returns_only_own_ended_sessions(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $game = Game::factory()->for($user)->create(['title' => 'Portal 2']);
        $othersGame = Game::factory()->for($other)->create();

        PlaySession::factory()->for($user)->for($game)->create();
        PlaySession::factory()->for($other)->for($othersGame)->create();
        PlaySession::factory()->for($user)->for($game)->active()->create();

        $this->actingAs($user)
            ->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.game.title', 'Portal 2')
            ->assertJsonMissingPath('data.0.user_id');
    }

    public function test_history_orders_by_ended_at_desc(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();

        $older = PlaySession::factory()->for($user)->for($game)->create([
            'started_at' => now()->subDays(3),
            'ended_at' => now()->subDays(3)->addHour(),
            'duration_seconds' => 3600,
        ]);
        $newer = PlaySession::factory()->for($user)->for($game)->create([
            'started_at' => now()->subHours(2),
            'ended_at' => now()->subHour(),
            'duration_seconds' => 3600,
        ]);

        $this->actingAs($user)
            ->getJson('/api/sessions')
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_history_paginates_at_15_per_page(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create();
        PlaySession::factory()->count(16)->for($user)->for($game)->create();

        $this->actingAs($user)
            ->getJson('/api/sessions')
            ->assertOk()
            ->assertJsonPath('meta.per_page', 15)
            ->assertJsonPath('meta.total', 16)
            ->assertJsonCount(15, 'data');
    }
}
