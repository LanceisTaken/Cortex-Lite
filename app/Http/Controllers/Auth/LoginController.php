<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        if (! Auth::guard('web')->attempt($credentials, remember: false)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();
        $user = $request->user();

        return response()->json(
            $user->only('id', 'name', 'email', 'email_verified_at', 'created_at'),
            200
        );
    }

    public function destroy(Request $request): Response
    {
        Auth::guard('web')->logout();

        // The `auth:sanctum` middleware on this route authenticates via the
        // 'web' session guard, then calls Auth::shouldUse('sanctum'), making
        // 'sanctum' the default guard for the rest of the request. Sanctum's
        // RequestGuard caches the resolved user on itself the first time it
        // is asked, so an unqualified Auth::user()/Auth::check() after this
        // point would still report authenticated unless we also clear that
        // cache. Auth::forgetUser() forwards to the current default guard.
        Auth::forgetUser();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->noContent();
    }
}
