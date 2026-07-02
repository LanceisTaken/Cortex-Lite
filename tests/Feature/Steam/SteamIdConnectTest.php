<?php

namespace Tests\Feature\Steam;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SteamIdConnectTest extends TestCase
{
    use RefreshDatabase;

    public function test_connect_steam_id_persists_the_submitted_steam_id(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/steam/connect-id', ['steam_id' => '76561198000000000'])
            ->assertOk()
            ->assertJsonPath('steam_id', '76561198000000000');

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'steam_id' => '76561198000000000',
        ]);
    }

    public function test_connect_steam_id_returns_409_when_steam_id_is_already_claimed(): void
    {
        $user = User::factory()->create();
        User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $this->actingAs($user)
            ->postJson('/api/steam/connect-id', ['steam_id' => '76561198000000000'])
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'steam_id_already_linked');
    }

    public function test_connect_steam_id_validates_the_input_shape(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/steam/connect-id', ['steam_id' => 'not-a-steamid'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('steam_id');
    }

    public function test_connect_steam_id_route_is_throttled(): void
    {
        $user = User::factory()->create();

        for ($i = 0; $i < 6; $i++) {
            $steamId = '7656119'.str_pad((string) $i, 10, '0', STR_PAD_LEFT);

            $this->actingAs($user)
                ->postJson('/api/steam/connect-id', ['steam_id' => $steamId])
                ->assertOk();
        }

        $nextSteamId = '7656119'.str_pad('6', 10, '0', STR_PAD_LEFT);

        $response = $this->actingAs($user)
            ->postJson('/api/steam/connect-id', ['steam_id' => $nextSteamId]);

        $response->assertStatus(429);
        $this->assertIsNumeric($response->headers->get('Retry-After'));
    }
}
