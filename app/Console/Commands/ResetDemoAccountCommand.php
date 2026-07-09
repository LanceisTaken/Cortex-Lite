<?php

namespace App\Console\Commands;

use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Console\Command;

class ResetDemoAccountCommand extends Command
{
    protected $signature = 'demo:reset';

    protected $description = 'Reset the evaluator demo account to its canonical state.';

    public function handle(DemoAccountProvisioner $provisioner): int
    {
        $user = $provisioner->reset();

        $this->info("Demo account reset: {$user->email} ({$user->games()->count()} games).");

        return self::SUCCESS;
    }
}
