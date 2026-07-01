<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class RegisterTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_register_with_valid_data(): void
    {
        Event::fake();

        $response = $this->postJson('/api/register', [
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('name', 'Ada Lovelace')
            ->assertJsonPath('email', 'ada@example.com')
            ->assertJsonMissing(['password']);

        $this->assertDatabaseHas('users', ['email' => 'ada@example.com']);
        Event::assertDispatched(Registered::class);
        $this->assertAuthenticated();
    }

    public function test_registration_rejects_duplicate_email(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $response = $this->postJson('/api/register', [
            'name' => 'Someone',
            'email' => 'taken@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_registration_rejects_weak_password(): void
    {
        $response = $this->postJson('/api/register', [
            'name' => 'Someone',
            'email' => 'weak@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('password');
    }

    public function test_registration_ignores_mass_assigned_fields(): void
    {
        $this->postJson('/api/register', [
            'name' => 'Ada',
            'email' => 'ada@example.com',
            'password' => 'SecurePass123',
            'password_confirmation' => 'SecurePass123',
            'is_admin' => true,
            'email_verified_at' => now()->toIso8601String(),
            'stripe_id' => 'cus_hackme',
        ])->assertStatus(201);

        $user = User::where('email', 'ada@example.com')->firstOrFail();
        $this->assertNull($user->email_verified_at);
        $this->assertNull($user->stripe_id);
        $this->assertObjectNotHasProperty('is_admin', $user);
    }
}
