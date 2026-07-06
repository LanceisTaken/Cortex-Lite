<?php

namespace App\Providers;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Cashier\Cashier;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        Cashier::ignoreRoutes();
    }

    public function boot(): void
    {
        VerifyEmail::createUrlUsing(function ($notifiable) {
            $backendSigned = URL::temporarySignedRoute(
                'verification.verify',
                now()->addMinutes(config('auth.verification.expire', 60)),
                [
                    'id' => $notifiable->getKey(),
                    'hash' => sha1($notifiable->getEmailForVerification()),
                ]
            );
            $query = parse_url($backendSigned, PHP_URL_QUERY);
            return config('app.frontend_url')
                .'/verify-email/'.$notifiable->getKey().'/'
                .sha1($notifiable->getEmailForVerification())
                .'?'.$query;
        });

        ResetPassword::createUrlUsing(fn ($user, string $token) =>
            config('app.frontend_url')
            .'/reset-password/'.$token
            .'?email='.urlencode($user->email)
        );

        Password::defaults(function () {
            $rule = Password::min(8)->mixedCase()->numbers();
            return app()->runningUnitTests() ? $rule : $rule->uncompromised();
        });
    }
}
