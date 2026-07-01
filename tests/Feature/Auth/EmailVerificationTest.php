<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class EmailVerificationTest extends TestCase
{
    use RefreshDatabase;

    private function verifyUrl(User $user): string
    {
        return URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $user->id, 'hash' => sha1($user->email)]
        );
    }

    public function test_verify_link_marks_email_verified(): void
    {
        Event::fake();
        $user = User::factory()->unverified()->create();

        $response = $this->actingAs($user)->post($this->verifyUrl($user));

        $response->assertNoContent();
        $this->assertNotNull($user->fresh()->email_verified_at);
        Event::assertDispatched(Verified::class);
    }

    public function test_verify_link_rejects_bad_signature(): void
    {
        $user = User::factory()->unverified()->create();
        $url = $this->verifyUrl($user).'&tampered=1';

        $this->actingAs($user)->post($url)->assertStatus(403);
        $this->assertNull($user->fresh()->email_verified_at);
    }

    public function test_verify_link_rejects_wrong_hash(): void
    {
        $userA = User::factory()->unverified()->create();
        $userB = User::factory()->unverified()->create();

        $urlForA = URL::temporarySignedRoute(
            'verification.verify',
            now()->addMinutes(60),
            ['id' => $userB->id, 'hash' => sha1($userA->email)] // wrong pairing
        );

        $this->actingAs($userB)->post($urlForA)->assertStatus(403);
        $this->assertNull($userB->fresh()->email_verified_at);
    }

    public function test_resend_link_sends_notification(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertStatus(202);

        Notification::assertSentTo($user, VerifyEmail::class);
    }

    public function test_resend_throttled_after_six_requests(): void
    {
        Notification::fake();
        $user = User::factory()->unverified()->create();

        for ($i = 0; $i < 6; $i++) {
            $this->actingAs($user)
                ->postJson('/api/email/verification-notification')
                ->assertStatus(202);
        }

        $this->actingAs($user)
            ->postJson('/api/email/verification-notification')
            ->assertStatus(429);
    }
}
