<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_rejected_401(): void
    {
        $this->getJson('/api/usage')->assertStatus(401);
    }

    public function test_free_user_sees_counts_and_limits(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.is_premium', false)
            ->assertJsonPath('data.window_days', 30)
            ->assertJsonPath('data.recommend.used', 1)
            ->assertJsonPath('data.recommend.limit', 3)
            ->assertJsonPath('data.recommend.remaining', 2)
            ->assertJsonPath('data.reverse.used', 0)
            ->assertJsonPath('data.reverse.limit', 5)
            ->assertJsonPath('data.reverse.remaining', 5);
    }

    public function test_premium_user_sees_null_limits(): void
    {
        $user = User::factory()->create(['is_premium' => true]);

        $this->actingAs($user)
            ->getJson('/api/usage')
            ->assertOk()
            ->assertJsonPath('data.is_premium', true)
            ->assertJsonPath('data.recommend.limit', null)
            ->assertJsonPath('data.recommend.remaining', null)
            ->assertJsonPath('data.reverse.limit', null)
            ->assertJsonPath('data.reverse.remaining', null);
    }
}
