<?php

use App\Exceptions\QuotaExceededException;
use App\Exceptions\SteamApiException;
use App\Services\UsageQuota;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('steam:sync-all')->daily()->withoutOverlapping();
        $schedule->command('games:enrich-metadata')->everyFiveMinutes()->withoutOverlapping()->runInBackground();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        $middleware->validateCsrfTokens(except: [
            'api/stripe/webhook',
        ]);

        // JSON clients get 409 (resource-state conflict) for an unverified
        // email instead of the framework default 403. See
        // App\Http\Middleware\EnsureEmailIsVerified for rationale.
        $middleware->alias([
            'verified' => \App\Http\Middleware\EnsureEmailIsVerified::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );

        $exceptions->render(function (SteamApiException $exception, Request $request): ?\Illuminate\Http\JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error_code' => 'steam_api_unavailable',
                'message' => 'Steam is temporarily unavailable. Please try again shortly.',
            ], $exception->statusCode());
        });

        $exceptions->render(function (QuotaExceededException $exception, Request $request): ?\Illuminate\Http\JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'error_code' => 'quota_exceeded',
                'type' => $exception->type,
                'limit' => $exception->limit,
                'used' => $exception->used,
                'window_days' => UsageQuota::WINDOW_DAYS,
                'message' => "You've used all {$exception->limit} free {$exception->type} calls in the last "
                    .UsageQuota::WINDOW_DAYS.' days. Upgrade to Cortex Premium for unlimited access.',
            ], 402);
        });
    })->create();
