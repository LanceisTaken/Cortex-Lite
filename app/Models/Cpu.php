<?php

namespace App\Models;

use Database\Factories\CpuFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['name', 'manufacturer', 'single_thread_mark', 'tier', 'released_year'])]
class Cpu extends Model
{
    /** @use HasFactory<CpuFactory> */
    use HasFactory;

    public const TIERS = ['low', 'mid', 'high', 'enthusiast'];

    public const RESPONSE_FIELDS = [
        'id',
        'name',
        'manufacturer',
        'single_thread_mark',
        'tier',
        'released_year',
    ];

    protected function casts(): array
    {
        return [
            'single_thread_mark' => 'integer',
            'released_year' => 'integer',
        ];
    }
}
