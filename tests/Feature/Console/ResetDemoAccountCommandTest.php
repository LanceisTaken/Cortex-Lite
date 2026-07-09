<?php

namespace Tests\Feature\Console;

use App\Models\User;
use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ResetDemoAccountCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_provisions_the_demo_account(): void
    {
        $this->artisan('demo:reset')->assertExitCode(0);

        $this->assertDatabaseHas('users', [
            'email' => DemoAccountProvisioner::EMAIL,
            'is_premium' => false,
        ]);

        $user = User::where('email', DemoAccountProvisioner::EMAIL)->firstOrFail();

        $this->assertSame(10, $user->games()->count());
    }
}
