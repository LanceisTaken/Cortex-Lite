<?php

namespace App\Models;

use Database\Factories\GpuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'manufacturer', 'g3d_mark', 'tier', 'released_year'])]
class Gpu extends Model
{
    /** @use HasFactory<GpuFactory> */
    use HasFactory;

    public const TIERS = ['low', 'mid', 'high', 'enthusiast'];

    public const RESPONSE_FIELDS = [
        'id',
        'name',
        'manufacturer',
        'g3d_mark',
        'tier',
        'released_year',
    ];

    protected function casts(): array
    {
        return [
            'g3d_mark' => 'integer',
            'released_year' => 'integer',
        ];
    }
}
