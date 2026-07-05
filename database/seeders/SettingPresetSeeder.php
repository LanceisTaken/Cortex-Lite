<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Seeds curated anchor presets used to calibrate deterministic recommendations.
 */
class SettingPresetSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('data/setting_presets.json');
        $payload = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

        $now = now();
        $records = array_map(fn (array $row) => [
            'game' => $row['game'],
            'steam_app_id' => $row['steam_app_id'],
            'goal' => $row['goal'],
            'gpu_tier' => $row['gpu_tier'],
            'settings' => json_encode($row['settings'], JSON_THROW_ON_ERROR),
            'notes' => $row['notes'],
            'created_at' => $now,
            'updated_at' => $now,
        ], $payload['presets']);

        DB::table('setting_presets')->upsert(
            $records,
            uniqueBy: ['game', 'goal', 'gpu_tier'],
            update: ['steam_app_id', 'settings', 'notes', 'updated_at'],
        );
    }
}
