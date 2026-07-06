<?php

namespace Tests\Feature\Billing;

use App\Models\UsageEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UsageEventSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_users_default_to_non_premium(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->is_premium);
        $this->assertIsBool($user->fresh()->is_premium);
    }

    public function test_user_has_many_usage_events(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);
        $user->usageEvents()->create(['type' => 'reverse']);

        $this->assertSame(2, $user->usageEvents()->count());
        $this->assertContains('recommend', $user->usageEvents()->pluck('type')->all());
    }

    public function test_deleting_a_user_cascades_usage_events(): void
    {
        $user = User::factory()->create();
        $user->usageEvents()->create(['type' => 'recommend']);

        $user->delete();

        $this->assertSame(0, UsageEvent::query()->count());
    }

    public function test_me_endpoint_exposes_is_premium(): void
    {
        $user = User::factory()->create(['is_premium' => true]);

        $this->actingAs($user)
            ->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('is_premium', true);
    }
}
