<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CheckoutEndpointTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_is_rejected_401(): void
    {
        $this->postJson('/api/checkout')->assertStatus(401);
    }

    public function test_unconfigured_price_returns_500_with_a_clear_message(): void
    {
        config(['services.stripe.price' => null]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/checkout')
            ->assertStatus(500)
            ->assertJsonPath('error_code', 'stripe_not_configured');
    }
}
