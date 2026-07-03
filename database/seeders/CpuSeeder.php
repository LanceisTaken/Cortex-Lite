<?php

namespace Database\Seeders;

use App\Support\Hardware\CpuTierClassifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds raw CPU benchmark data and derives tier from CpuTierClassifier.
 */
class CpuSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/cpus.json');
        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $now = now();
        $records = array_map(fn (array $row) => [
            'name' => $row['name'],
            'manufacturer' => $row['manufacturer'],
            'single_thread_mark' => $row['single_thread_mark'],
            'tier' => CpuTierClassifier::classify($row['single_thread_mark']),
            'released_year' => $row['released_year'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('cpus')->upsert(
            $records,
            uniqueBy: ['name'],
            update: ['manufacturer', 'single_thread_mark', 'tier', 'released_year', 'updated_at'],
        );
    }
}
