<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecommendEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function scenario(?callable $tweakGame = null): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create(['steam_app_id' => 700700]);

        if ($tweakGame !== null) {
            $tweakGame($game);
        }

        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        return [$user, [
            'game_id' => $game->id,
            'gpu_id' => $gpu->id,
            'cpu_id' => $cpu->id,
            'ram_gb' => 32,
            'goal' => 'quality',
        ]];
    }

    public function test_guest_is_rejected_401(): void
    {
        $this->postJson('/api/recommend', [])->assertStatus(401);
    }

    public function test_missing_fields_return_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/recommend', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['game_id', 'gpu_id', 'cpu_id', 'ram_gb', 'goal']);
    }

    public function test_invalid_goal_returns_422(): void
    {
        [$user, $payload] = $this->scenario();
        $payload['goal'] = 'cinematic';

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal');
    }

    public function test_nonexistent_game_returns_422(): void
    {
        [$user, $payload] = $this->scenario();
        $payload['game_id'] = 999999;

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('game_id');
    }

    public function test_another_users_game_returns_404_idor(): void
    {
        [$user, $payload] = $this->scenario();
        $othersGame = Game::factory()->for(User::factory())->create();
        $payload['game_id'] = $othersGame->id;

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertStatus(404);
    }

    public function test_anchor_hit_returns_settings_and_explanation(): void
    {
        [$user, $payload] = $this->scenario();
        SettingPreset::factory()->create([
            'steam_app_id' => 700700,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => ['upscaling' => 'DLSS Quality mode', 'shadow_quality' => 'ultra'],
        ]);

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk()
            ->assertJsonPath('data.source', 'anchor')
            ->assertJsonPath('data.gpu_tier', 'high')
            ->assertJsonPath('data.settings.upscaling', 'DLSS Quality mode')
            ->assertJsonPath('data.game_id', $payload['game_id'])
            ->assertJsonPath('data.goal', 'quality')
            ->assertJsonStructure(['data' => ['settings', 'source', 'ram_bucket', 'cpu_bottleneck', 'explanation']]);
    }

    public function test_heuristic_fallback_when_no_anchor(): void
    {
        [$user, $payload] = $this->scenario();

        $this->actingAs($user)
            ->postJson('/api/recommend', $payload)
            ->assertOk()
            ->assertJsonPath('data.source', 'heuristic')
            ->assertJsonStructure(['data' => ['settings' => ['texture_quality'], 'explanation']]);
    }
}
