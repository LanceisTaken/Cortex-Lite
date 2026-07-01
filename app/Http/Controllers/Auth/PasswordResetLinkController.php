<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Password;

class PasswordResetLinkController extends Controller
{
    public function store(ForgotPasswordRequest $request): JsonResponse
    {
        // Result intentionally ignored — enumeration guard.
        Password::sendResetLink($request->only('email'));

        return response()->json([
            'message' => 'If that email is in our system, we sent a reset link.',
        ]);
    }
}
