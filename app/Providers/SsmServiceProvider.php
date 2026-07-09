<?php

namespace App\Providers;

use Aws\Ssm\SsmClient;
use Illuminate\Support\ServiceProvider;

class SsmServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Production credentials come from the EC2 instance role via IMDSv2.
        $this->app->singleton(SsmClient::class, fn () => new SsmClient([
            'region' => config('services.ssm.region'),
            'version' => '2014-11-06',
        ]));
    }
}
