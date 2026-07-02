<?php

namespace Tests\Feature\Steam;

use App\Models\Game;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SteamSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_services_config_exposes_steam_api_key(): void
    {
        config()->set('services.steam.api_key', 'steam-test-key');

        $this->assertSame('steam-test-key', config('services.steam.api_key'));
    }

    public function test_user_model_does_not_mass_assign_steam_id(): void
    {
        $user = User::create([
            'name' => 'Steam Guard',
            'email' => 'steam-guard@example.com',
            'password' => 'password',
            'steam_id' => '76561198000000000',
        ]);

        $this->assertNull($user->steam_id);
    }

    public function test_users_steam_id_must_be_unique(): void
    {
        User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);

        $this->expectException(QueryException::class);

        User::factory()->create([
            'steam_id' => '76561198000000000',
        ]);
    }

    public function test_games_unique_index_rejects_duplicate_steam_app_ids_per_user(): void
    {
        $user = User::factory()->create();

        Game::factory()->for($user)->create([
            'steam_app_id' => 620,
            'source' => 'steam',
        ]);

        $this->expectException(QueryException::class);

        Game::factory()->for($user)->create([
            'steam_app_id' => 620,
            'source' => 'steam',
        ]);
    }

    public function test_games_unique_index_allows_multiple_manual_rows_with_null_steam_app_id(): void
    {
        $user = User::factory()->create();

        Game::factory()->for($user)->create([
            'steam_app_id' => null,
            'source' => 'manual',
        ]);

        Game::factory()->for($user)->create([
            'steam_app_id' => null,
            'source' => 'manual',
        ]);

        $this->assertSame(2, $user->games()->count());
    }
}
