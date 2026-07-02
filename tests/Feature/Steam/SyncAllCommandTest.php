<?php

namespace Tests\Feature\Steam;

use App\Models\User;
use App\Services\SteamLibrarySynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class SyncAllCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_runs_cleanly_with_zero_connected_users(): void
    {
        Log::spy();

        $this->artisan('steam:sync-all')->assertExitCode(0);

        Log::shouldHaveReceived('info')->with('Starting Steam sync batch.', ['count' => 0])->once();
    }

    public function test_command_continues_past_a_failing_user(): void
    {
        $users = User::factory()->count(3)->create();
        $users[0]->forceFill(['steam_id' => '76561198000000001'])->save();
        $users[1]->forceFill(['steam_id' => '76561198000000002'])->save();
        $users[2]->forceFill(['steam_id' => '76561198000000003'])->save();

        Log::spy();

        $synchronizer = Mockery::mock(SteamLibrarySynchronizer::class);
        $synchronizer->shouldReceive('sync')->once()->withArgs(fn ($user) => $user->is($users[0]))->andReturn(['imported' => 1, 'updated' => 0]);
        $synchronizer->shouldReceive('sync')->once()->withArgs(fn ($user) => $user->is($users[1]))->andThrow(new \RuntimeException('boom'));
        $synchronizer->shouldReceive('sync')->once()->withArgs(fn ($user) => $user->is($users[2]))->andReturn(['imported' => 0, 'updated' => 2]);
        $this->app->instance(SteamLibrarySynchronizer::class, $synchronizer);

        $this->artisan('steam:sync-all')->assertExitCode(0);

        Log::shouldHaveReceived('warning')->once();
        Log::shouldHaveReceived('info')->times(3);
    }

    public function test_schedule_registers_steam_sync_all_daily(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('steam:sync-all')
            ->assertExitCode(0);
    }
}
