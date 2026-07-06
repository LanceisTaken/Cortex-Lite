<?php

namespace Tests\Feature\Recommendations;

use App\Models\Cpu;
use App\Models\Game;
use App\Models\Gpu;
use App\Models\SettingPreset;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ReverseEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @param  array<string, mixed>  $anchorSettings
     * @param  array<string, mixed>  $currentSettings
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function scenario(array $anchorSettings, array $currentSettings): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->for($user)->create([
            'steam_app_id' => 700700,
            'title' => 'Test Game',
        ]);
        $gpu = Gpu::factory()->create(['tier' => 'high', 'g3d_mark' => 15000]);
        $cpu = Cpu::factory()->create(['tier' => 'high', 'single_thread_mark' => 3800]);

        SettingPreset::factory()->create([
            'game' => 'Test Game',
            'steam_app_id' => 700700,
            'goal' => 'quality',
            'gpu_tier' => 'high',
            'settings' => $anchorSettings,
        ]);

        return [$user, [
            'game_id' => $game->id,
            'gpu_id' => $gpu->id,
            'cpu_id' => $cpu->id,
            'ram_gb' => 32,
            'goal' => 'quality',
            'current_settings' => $currentSettings,
        ]];
    }

    public function test_guest_is_rejected_401(): void
    {
        $this->postJson('/api/reverse', [])->assertStatus(401);
    }

    public function test_missing_fields_return_422(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/reverse', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['game_id', 'gpu_id', 'cpu_id', 'ram_gb', 'goal', 'current_settings']);
    }

    public function test_current_settings_must_be_an_object_not_a_string(): void
    {
        [$user, $payload] = $this->scenario(['texture_quality' => 'medium'], ['texture_quality' => 'ultra']);
        $payload['current_settings'] = 'texture_quality=ultra';

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('current_settings');
    }

    public function test_invalid_goal_returns_422(): void
    {
        [$user, $payload] = $this->scenario(['texture_quality' => 'medium'], ['texture_quality' => 'ultra']);
        $payload['goal'] = 'cinematic';

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors('goal');
    }

    public function test_another_users_game_returns_404_idor(): void
    {
        [$user, $payload] = $this->scenario(['texture_quality' => 'medium'], ['texture_quality' => 'ultra']);
        $othersGame = Game::factory()->for(User::factory())->create();
        $payload['game_id'] = $othersGame->id;

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(404);
    }

    public function test_returns_the_diff_and_explanation(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium', 'ray_tracing' => false],
            ['texture_quality' => 'ultra', 'ray_tracing' => true],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk()
            ->assertJsonPath('data.game_id', $payload['game_id'])
            ->assertJsonPath('data.goal', 'quality')
            ->assertJsonPath('data.recommendation.source', 'anchor')
            ->assertJsonPath('data.diff.0.setting', 'texture_quality')
            ->assertJsonPath('data.diff.0.label', 'ultra → medium')
            ->assertJsonPath('data.diff.1.setting', 'ray_tracing')
            ->assertJsonPath('data.diff.1.label', 'on → off')
            ->assertJsonStructure(['data' => ['diff', 'recommendation' => ['settings', 'source'], 'explanation']]);
    }

    public function test_already_optimal_settings_return_an_empty_diff(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'medium'],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk()
            ->assertJsonPath('data.diff', [])
            ->assertJsonStructure(['data' => ['explanation']]);
    }

    public function test_successful_reverse_records_a_usage_event(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk();

        $this->assertSame(1, $user->usageEvents()->where('type', 'reverse')->count());
    }

    public function test_free_user_is_blocked_with_402_after_five_reverse_calls(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/reverse', $payload)->assertOk();
        }

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertStatus(402)
            ->assertJsonPath('error_code', 'quota_exceeded')
            ->assertJsonPath('type', 'reverse')
            ->assertJsonPath('limit', 5);

        $this->assertSame(5, $user->usageEvents()->where('type', 'reverse')->count());
    }

    public function test_reverse_and_recommend_quotas_are_independent(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user)->postJson('/api/reverse', $payload)->assertOk();
        }

        $this->actingAs($user)->postJson('/api/reverse', $payload)->assertStatus(402);

        $this->assertSame(0, $user->usageEvents()->where('type', 'recommend')->count());
    }

    public function test_premium_user_is_never_capped_on_reverse(): void
    {
        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );
        $user->forceFill(['is_premium' => true])->save();

        for ($i = 0; $i < 7; $i++) {
            $this->actingAs($user)->postJson('/api/reverse', $payload)->assertOk();
        }

        $this->assertTrue(true);
    }

    public function test_gemini_prose_is_returned_when_configured(): void
    {
        config()->set('services.gemini.api_key', 'test-key');
        config()->set('services.gemini.cache_store', 'array');
        Cache::store('array')->flush();
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [['content' => ['parts' => [['text' => 'AI diff explanation.']]]]],
            ]),
        ]);

        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk()
            ->assertJsonPath('data.explanation', 'AI diff explanation.');
    }

    public function test_falls_back_to_static_explanation_without_gemini_key(): void
    {
        config()->set('services.gemini.api_key', '');
        config()->set('services.gemini.cache_store', 'array');
        Cache::store('array')->flush();
        Http::fake();

        [$user, $payload] = $this->scenario(
            ['texture_quality' => 'medium'],
            ['texture_quality' => 'ultra'],
        );

        $response = $this->actingAs($user)
            ->postJson('/api/reverse', $payload)
            ->assertOk();

        $this->assertStringContainsString('align your settings', (string) $response->json('data.explanation'));
        Http::assertNothingSent();
    }
}
