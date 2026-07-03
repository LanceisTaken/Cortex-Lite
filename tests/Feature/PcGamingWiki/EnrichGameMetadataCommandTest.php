<?php

namespace Tests\Feature\PcGamingWiki;

use App\Exceptions\PcGamingWikiApiException;
use App\Exceptions\PcGamingWikiRateLimitException;
use App\Models\Game;
use App\Models\User;
use App\Services\PcGamingWikiClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class EnrichGameMetadataCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_enriches_pending_games(): void
    {
        $games = $this->pendingGames(3);
        $client = Mockery::mock(PcGamingWikiClient::class);

        foreach ($games as $game) {
            $client->shouldReceive('fetchMetadata')
                ->once()
                ->with($game->steam_app_id)
                ->andReturn($this->metadata());
        }

        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata')->assertExitCode(0);

        $this->assertSame(3, Game::where('metadata_status', 'ok')->count());
        $this->assertDatabaseCount('game_metadata', 3);
    }

    public function test_null_metadata_marks_game_missing(): void
    {
        $game = $this->pendingGames(1)[0];
        $client = Mockery::mock(PcGamingWikiClient::class);
        $client->shouldReceive('fetchMetadata')->once()->with($game->steam_app_id)->andReturn(null);
        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata')->assertExitCode(0);

        $this->assertSame('missing', $game->fresh()->metadata_status);
        $this->assertDatabaseCount('game_metadata', 0);
    }

    public function test_api_failure_marks_game_missing_and_continues(): void
    {
        Log::spy();
        $games = $this->pendingGames(3);
        $client = Mockery::mock(PcGamingWikiClient::class);
        $client->shouldReceive('fetchMetadata')->once()->with($games[0]->steam_app_id)->andReturn($this->metadata());
        $client->shouldReceive('fetchMetadata')->once()->with($games[1]->steam_app_id)->andThrow(new PcGamingWikiApiException('boom'));
        $client->shouldReceive('fetchMetadata')->once()->with($games[2]->steam_app_id)->andReturn($this->metadata());
        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata')->assertExitCode(0);

        $this->assertSame('ok', $games[0]->fresh()->metadata_status);
        $this->assertSame('missing', $games[1]->fresh()->metadata_status);
        $this->assertSame('ok', $games[2]->fresh()->metadata_status);
        Log::shouldHaveReceived('warning')->once();
    }

    public function test_rate_limit_leaves_remaining_rows_pending(): void
    {
        $games = $this->pendingGames(3);
        $client = Mockery::mock(PcGamingWikiClient::class);
        $client->shouldReceive('fetchMetadata')->once()->with($games[0]->steam_app_id)->andReturn($this->metadata());
        $client->shouldReceive('fetchMetadata')->once()->with($games[1]->steam_app_id)->andThrow(new PcGamingWikiRateLimitException('slow down'));
        $client->shouldNotReceive('fetchMetadata')->with($games[2]->steam_app_id);
        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata')->assertExitCode(0);

        $this->assertSame('ok', $games[0]->fresh()->metadata_status);
        $this->assertSame('pending', $games[1]->fresh()->metadata_status);
        $this->assertSame('pending', $games[2]->fresh()->metadata_status);
    }

    public function test_empty_pending_queue_does_not_call_client(): void
    {
        $client = Mockery::mock(PcGamingWikiClient::class);
        $client->shouldNotReceive('fetchMetadata');
        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata')->assertExitCode(0);
    }

    public function test_pending_game_without_steam_app_id_is_marked_missing(): void
    {
        $game = Game::factory()->for(User::factory())->create([
            'metadata_status' => 'pending',
            'steam_app_id' => null,
        ]);

        $client = Mockery::mock(PcGamingWikiClient::class);
        $client->shouldNotReceive('fetchMetadata');
        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata')->assertExitCode(0);

        $this->assertSame('missing', $game->fresh()->metadata_status);
    }

    public function test_limit_option_caps_batch_size(): void
    {
        $games = $this->pendingGames(3);
        $client = Mockery::mock(PcGamingWikiClient::class);
        $client->shouldReceive('fetchMetadata')->once()->with($games[0]->steam_app_id)->andReturn($this->metadata());
        $client->shouldNotReceive('fetchMetadata')->with($games[1]->steam_app_id);
        $this->app->instance(PcGamingWikiClient::class, $client);

        $this->artisan('games:enrich-metadata --limit=1')->assertExitCode(0);

        $this->assertSame(1, Game::where('metadata_status', 'ok')->count());
        $this->assertSame(2, Game::where('metadata_status', 'pending')->count());
    }

    public function test_schedule_registers_metadata_enrichment(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('games:enrich-metadata')
            ->assertExitCode(0);
    }

    private function pendingGames(int $count): array
    {
        $user = User::factory()->create();

        return Game::factory()
            ->count($count)
            ->for($user)
            ->sequence(fn ($sequence) => [
                'source' => 'steam',
                'metadata_status' => 'pending',
                'steam_app_id' => 700 + $sequence->index,
            ])
            ->create()
            ->all();
    }

    private function metadata(): array
    {
        return [
            'direct3d_versions' => ['12'],
            'vulkan_supported' => true,
            'hdr_supported' => true,
            'ultrawide_supported' => true,
            'dlss_supported' => true,
            'fsr_supported' => false,
            'ray_tracing_supported' => false,
            'raw_response' => ['cargoquery' => []],
        ];
    }
}
