<?php

namespace Tests\Feature\Console;

use Aws\MockHandler;
use Aws\Result;
use Aws\Ssm\SsmClient;
use Tests\TestCase;

class SsmExportCommandTest extends TestCase
{
    public function test_prints_export_lines_for_each_parameter(): void
    {
        $mock = new MockHandler();
        $mock->append(new Result([
            'Parameters' => [
                ['Name' => '/cortex-lite/STRIPE_SECRET', 'Value' => 'sk_test_123'],
                ['Name' => '/cortex-lite/DB_PASSWORD', 'Value' => "p'wd"],
            ],
            'NextToken' => null,
        ]));

        $this->app->instance(SsmClient::class, new SsmClient([
            'region' => 'ap-southeast-1',
            'version' => '2014-11-06',
            'credentials' => [
                'key' => 'test',
                'secret' => 'test',
            ],
            'handler' => $mock,
        ]));

        $this->artisan('ssm:export')
            ->expectsOutput("export STRIPE_SECRET='sk_test_123'")
            ->expectsOutput("export DB_PASSWORD='p'\\''wd'")
            ->assertExitCode(0);
    }
}
