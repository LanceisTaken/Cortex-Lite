<?php

namespace Database\Seeders;

use App\Services\Demo\DemoAccountProvisioner;
use Illuminate\Database\Seeder;

class DemoAccountSeeder extends Seeder
{
    public function run(): void
    {
        app(DemoAccountProvisioner::class)->reset();
    }
}
