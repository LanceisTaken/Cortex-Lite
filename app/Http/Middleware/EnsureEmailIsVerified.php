<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Overrides Laravel's default \Illuminate\Auth\Middleware\EnsureEmailIsVerified.
 *
 * The framework default aborts with 403 for JSON requests. This API treats an
 * unverified email as a resource-state conflict rather than a bare
 * authorization failure, so JSON clients get 409 instead of 403. Non-JSON
 * (web) requests keep the standard redirect-to-verification-notice behavior.
 */
class EnsureEmailIsVerified
{
    public function handle(Request $request, Closure $next, ?string $redirectToRoute = null)
    {
        if (! $request->user() ||
            ($request->user() instanceof MustVerifyEmail &&
            ! $request->user()->hasVerifiedEmail())) {
            if ($request->expectsJson()) {
                throw new HttpException(409, 'Your email address is not verified.');
            }

            return Redirect::guest(URL::route($redirectToRoute ?: 'verification.notice'));
        }

        return $next($request);
    }
}
