<?php

namespace App\Models;

use Database\Factories\GameFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'title',
    'platform',
    'genre',
    'status',
    'playtime_minutes',
    'last_played_at',
    'steam_app_id',
    'source',
    'metadata_status',
    'cover_url',
])]
#[Hidden(['user_id'])]
class Game extends Model
{
    /** @use HasFactory<GameFactory> */
    use HasFactory;

    public const RESPONSE_FIELDS = [
        'id',
        'title',
        'platform',
        'genre',
        'status',
        'playtime_minutes',
        'last_played_at',
        'steam_app_id',
        'source',
        'metadata_status',
        'cover_url',
        'created_at',
        'updated_at',
    ];

    protected function casts(): array
    {
        return [
            'last_played_at' => 'datetime',
            'playtime_minutes' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
