<?php

namespace App\Models;

use Database\Factories\GameMetadataFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'game_id',
    'direct3d_versions',
    'vulkan_supported',
    'hdr_supported',
    'ultrawide_supported',
    'dlss_supported',
    'fsr_supported',
    'ray_tracing_supported',
    'raw_response',
])]
class GameMetadata extends Model
{
    /** @use HasFactory<GameMetadataFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'direct3d_versions' => 'array',
            'raw_response' => 'array',
            'vulkan_supported' => 'boolean',
            'hdr_supported' => 'boolean',
            'ultrawide_supported' => 'boolean',
            'dlss_supported' => 'boolean',
            'fsr_supported' => 'boolean',
            'ray_tracing_supported' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }
}
