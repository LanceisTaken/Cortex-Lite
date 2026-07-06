<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CheckoutController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $price = config('services.stripe.price');

        if (blank($price)) {
            return response()->json([
                'error_code' => 'stripe_not_configured',
                'message' => 'The Cortex Premium price is not configured.',
            ], 500);
        }

        $frontend = rtrim((string) config('app.frontend_url'), '/');

        $checkout = $request->user()
            ->newSubscription('default', $price)
            ->checkout([
                'success_url' => $frontend.'/dashboard?checkout=success',
                'cancel_url' => $frontend.'/dashboard?checkout=cancelled',
            ]);

        return response()->json(['url' => $checkout->url]);
    }
}
