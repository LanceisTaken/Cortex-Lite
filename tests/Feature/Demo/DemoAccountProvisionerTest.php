<?php

namespace Tests\Feature\Demo;

use App\Models\Game;
use App\Models\User;
use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DemoAccountProvisionerTest extends TestCase
{
    use RefreshDatabase;

    public function test_reset_creates_demo_user_with_fixture_library(): void
    {
        $user = app(DemoAccountProvisioner::class)->reset();

        $this->assertSame(DemoAccountProvisioner::EMAIL, $user->email);
        $this->assertFalse($user->is_premium);
        $this->assertSame(10, $user->games()->count());
    }

    public function test_reset_is_idempotent_and_wipes_visitor_changes(): void
    {
        $provisioner = app(DemoAccountProvisioner::class);
        $user = $provisioner->reset();

        Game::factory()->for($user)->create(['title' => 'Junk Added By Visitor']);
        $user->forceFill(['is_premium' => true])->save();

        $provisioner->reset();

        $this->assertSame(10, $user->fresh()->games()->count());
        $this->assertFalse($user->fresh()->is_premium);
        $this->assertDatabaseMissing('games', ['title' => 'Junk Added By Visitor']);
    }

    public function test_reset_leaves_other_users_untouched(): void
    {
        $other = User::factory()->create();
        Game::factory()->for($other)->count(3)->create();

        app(DemoAccountProvisioner::class)->reset();

        $this->assertSame(3, $other->fresh()->games()->count());
    }
}
