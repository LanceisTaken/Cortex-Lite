<?php

namespace Tests\Unit;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class NotificationUrlTest extends TestCase
{
    use RefreshDatabase;

    public function test_verification_url_points_to_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $user->sendEmailVerificationNotification();

        Notification::assertSentTo($user, VerifyEmail::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith(
                config('app.frontend_url').'/verify-email/'.$user->id.'/'.sha1($user->email),
                $url
            );
            $this->assertStringContainsString('signature=', $url);
            $this->assertStringContainsString('expires=', $url);
            return true;
        });
    }

    public function test_reset_password_url_points_to_frontend(): void
    {
        Notification::fake();
        $user = User::factory()->create();

        $user->sendPasswordResetNotification('token-abc-123');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertSame(
                config('app.frontend_url').'/reset-password/token-abc-123?email='.urlencode($user->email),
                $url
            );
            return true;
        });
    }
}
