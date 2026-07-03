<?php

namespace App\Http\Controllers;

use App\Models\Cpu;
use App\Models\Gpu;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class HardwareController extends Controller
{
    public function gpus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->typeahead(
            Gpu::query()->select(Gpu::RESPONSE_FIELDS),
            'g3d_mark',
            $validated['search'] ?? null,
        );
    }

    public function cpus(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->typeahead(
            Cpu::query()->select(Cpu::RESPONSE_FIELDS),
            'single_thread_mark',
            $validated['search'] ?? null,
        );
    }

    private function typeahead(Builder $query, string $orderBy, ?string $search): JsonResponse
    {
        if (! empty($search)) {
            $search = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
            $query->whereRaw("name like ? escape '!'", ["%{$search}%"]);
        }

        return response()->json($query->orderByDesc($orderBy)->limit(20)->get());
    }
}
