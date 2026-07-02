<?php

namespace Tests\Feature\Account;

use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeleteAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_authed_user_can_delete_account(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->deleteJson('/api/account')
            ->assertNoContent();

        $this->assertDatabaseMissing('users', ['id' => $user->id]);
        $this->assertGuest();
    }

    public function test_delete_account_no_subscription_no_op(): void
    {
        $user = User::factory()->create();
        $this->assertFalse($user->subscribed('default'));

        $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_delete_account_cascades_games_via_fk(): void
    {
        $user = User::factory()->create();
        Game::factory()->count(2)->for($user)->create();
        $userId = $user->id;

        $this->actingAs($user)->deleteJson('/api/account')->assertNoContent();

        $this->assertSame(0, Game::where('user_id', $userId)->count());
    }

    public function test_unauthenticated_delete_returns_401(): void
    {
        $this->deleteJson('/api/account')->assertStatus(401);
    }
}
