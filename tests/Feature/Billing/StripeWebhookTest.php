<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StripeWebhookTest extends TestCase
{
    use RefreshDatabase;

    public function test_wrong_signature_is_rejected_with_400(): void
    {
        config(['cashier.webhook.secret' => 'whsec_test_secret']);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_test',
            'type' => 'customer.subscription.deleted',
        ], ['Stripe-Signature' => 't=1,v1=deadbeef'])
            ->assertStatus(400);
    }

    public function test_empty_webhook_secret_is_rejected_outside_local_and_testing(): void
    {
        $this->app['env'] = 'production';
        config(['cashier.webhook.secret' => null]);

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_test',
            'type' => 'customer.subscription.deleted',
        ])->assertStatus(400)
            ->assertSeeText('Webhook secret is not configured.');
    }

    public function test_subscription_deleted_flips_is_premium_to_false(): void
    {
        config(['cashier.webhook.secret' => null]);

        $user = User::factory()->create([
            'is_premium' => true,
            'stripe_id' => 'cus_test123',
        ]);

        $user->subscriptions()->create([
            'type' => 'default',
            'stripe_id' => 'sub_test123',
            'stripe_status' => 'active',
            'stripe_price' => 'price_test',
            'quantity' => 1,
        ]);

        $this->assertTrue($user->fresh()->subscribed('default'));

        $this->postJson('/api/stripe/webhook', [
            'id' => 'evt_test',
            'type' => 'customer.subscription.deleted',
            'data' => [
                'object' => [
                    'id' => 'sub_test123',
                    'customer' => 'cus_test123',
                    'status' => 'canceled',
                ],
            ],
        ])->assertOk();

        $this->assertFalse($user->fresh()->is_premium);
    }
}
