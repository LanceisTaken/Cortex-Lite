<?php

namespace App\Models;

use Database\Factories\SettingPresetFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['game', 'steam_app_id', 'goal', 'gpu_tier', 'settings', 'notes'])]
class SettingPreset extends Model
{
    /** @use HasFactory<SettingPresetFactory> */
    use HasFactory;

    public const GOALS = ['performance', 'balanced', 'quality'];

    public const GPU_TIERS = ['low', 'mid', 'high', 'enthusiast'];

    protected function casts(): array
    {
        return [
            'steam_app_id' => 'integer',
            'settings' => 'array',
        ];
    }
}
