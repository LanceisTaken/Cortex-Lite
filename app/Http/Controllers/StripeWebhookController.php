<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Laravel\Cashier\Cashier;
use Laravel\Cashier\Http\Controllers\WebhookController as CashierWebhookController;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook as StripeWebhook;
use Symfony\Component\HttpFoundation\Response;
use UnexpectedValueException;

class StripeWebhookController extends CashierWebhookController
{
    // Intentionally overrides Cashier's constructor to NOT register the
    // VerifyWebhookSignature middleware - we verify the signature once, manually,
    // in handleWebhook() so a bad signature returns 400 (not Cashier's 403).
    public function __construct()
    {
    }

    public function handleWebhook(Request $request): Response
    {
        $secret = config('cashier.webhook.secret');

        if (empty($secret) && ! app()->environment('local', 'testing')) {
            return new Response('Webhook secret is not configured.', 400);
        }

        if (! empty($secret)) {
            try {
                StripeWebhook::constructEvent(
                    $request->getContent(),
                    (string) $request->header('Stripe-Signature'),
                    $secret,
                );
            } catch (SignatureVerificationException|UnexpectedValueException) {
                return new Response('Invalid webhook signature.', 400);
            }
        }

        return parent::handleWebhook($request);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionCreated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionCreated($payload);
        $this->syncPremium($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionUpdated(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionUpdated($payload);
        $this->syncPremium($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function handleCustomerSubscriptionDeleted(array $payload): Response
    {
        $response = parent::handleCustomerSubscriptionDeleted($payload);
        $this->syncPremium($payload);

        return $response;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function syncPremium(array $payload): void
    {
        $customerId = $payload['data']['object']['customer'] ?? null;

        if ($customerId === null) {
            return;
        }

        $user = Cashier::findBillable($customerId);

        if ($user === null) {
            return;
        }

        $user->forceFill(['is_premium' => $user->fresh()->subscribed('default')])->save();
    }
}
