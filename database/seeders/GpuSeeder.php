<?php

namespace Database\Seeders;

use App\Support\Hardware\GpuTierClassifier;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds raw GPU benchmark data and derives tier from GpuTierClassifier.
 */
class GpuSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/gpus.json');
        $rows = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $now = now();
        $records = array_map(fn (array $row) => [
            'name' => $row['name'],
            'manufacturer' => $row['manufacturer'],
            'g3d_mark' => $row['g3d_mark'],
            'tier' => GpuTierClassifier::classify($row['g3d_mark']),
            'released_year' => $row['released_year'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $rows);

        DB::table('gpus')->upsert(
            $records,
            uniqueBy: ['name'],
            update: ['manufacturer', 'g3d_mark', 'tier', 'released_year', 'updated_at'],
        );
    }
}
