<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_login_succeeds(): void
    {
        $user = User::factory()->create(['password' => 'SecurePass123']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'SecurePass123',
        ]);

        $response->assertOk()
            ->assertJsonPath('email', $user->email)
            ->assertJsonMissing(['password']);
        $this->assertAuthenticatedAs($user);
    }

    public function test_invalid_credentials_return_generic_error(): void
    {
        $user = User::factory()->create(['password' => 'SecurePass123']);

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('email');
        // Error message must not reveal which field is wrong.
        $this->assertStringNotContainsString('password', strtolower(
            $response->json('errors.email.0')
        ));
        $this->assertGuest();
    }

    public function test_login_throttles_after_five_failures(): void
    {
        $user = User::factory()->create(['password' => 'SecurePass123']);

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/login', [
                'email' => $user->email,
                'password' => 'WrongPassword1',
            ])->assertStatus(422);
        }

        $response = $this->postJson('/api/login', [
            'email' => $user->email,
            'password' => 'WrongPassword1',
        ]);

        $response->assertStatus(429);
        $this->assertNotNull($response->headers->get('Retry-After'));
        $this->assertIsNumeric($response->headers->get('Retry-After'));
    }

    public function test_logout_invalidates_session(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->postJson('/api/logout')->assertNoContent();
        $this->assertGuest();
    }

    public function test_me_returns_authenticated_user(): void
    {
        $user = User::factory()->create(); // verified by default

        $this->actingAs($user)->getJson('/api/me')
            ->assertOk()
            ->assertJsonPath('id', $user->id)
            ->assertJsonPath('email', $user->email);
    }

    public function test_me_returns_401_for_guest(): void
    {
        $this->getJson('/api/me')->assertStatus(401);
    }

    public function test_me_returns_409_for_unverified_user(): void
    {
        $user = User::factory()->unverified()->create();
        $this->actingAs($user)->getJson('/api/me')->assertStatus(409);
    }
}
